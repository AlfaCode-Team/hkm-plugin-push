<?php

declare(strict_types=1);

namespace Plugins\Push\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\Push\Domain\DeviceToken;
use Plugins\Push\Domain\Notification;
use Plugins\Push\Domain\Platform;
use Plugins\Push\Domain\Provider;
use Plugins\Push\Domain\PushEnvironment;
use Plugins\Push\Domain\PushType;

/**
 * The delivery outbox.
 *
 * WHY AN OUTBOX AND NOT JUST A QUEUE JOB
 * --------------------------------------
 * A queued JOB carries the intent — "notify these users" — and re-running it
 * after a partial failure re-sends to the devices that already succeeded. A
 * row per DEVICE is the unit that can be retried without duplicating, because
 * each row's state is its own.
 *
 * It also survives the queue. A `QueuePort` is at-least-once at best and may
 * not be configured at all; a table is the record either way, so `push:drain`
 * works on a deployment with no worker running.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * THE DUPLICATE A RETRY CANNOT AVOID
 * ────────────────────────────────────────────────────────────────────────────
 * A row is marked delivered AFTER the provider answers. If the process dies in
 * between — the request succeeded, the UPDATE never ran — the row is still
 * pending and will be sent again. One notification, delivered twice.
 *
 * That is not fixable from here, and pretending otherwise would be worse than
 * admitting it: neither APNs nor FCM offers an idempotency key, so there is no
 * way to ask "did you already take this one?". What CAN be done is make the
 * duplicate harmless, and `apns-collapse-id` / `collapse_key` do exactly that —
 * two notifications with the same collapse id REPLACE each other on the device
 * rather than stacking. A caller who sets one on anything important turns a
 * visible double-buzz into nothing at all.
 *
 * The alternative — marking delivered BEFORE sending — trades a rare duplicate
 * for a rare silent loss, and for a notification a loss is the worse failure.
 */
final class OutboxRepository
{
    public function __construct(private readonly DatabasePort $db)
    {
    }

    /** Queue one notification for one device. */
    public function queue(DeviceToken $device, Notification $notification, string $now): void
    {
        try {
            $this->db->execute(
                'INSERT INTO push_outbox
                    (public_id, token, fid, platform, provider, environment, user_id,
                     notification, status, attempts, available_at, created_at, updated_at)
                 VALUES
                    (:public_id, :token, :fid, :platform, :provider, :environment, :user_id,
                     :notification, \'pending\', 0, :available_at, :created_at, :updated_at)',
                [
                    'public_id'    => $this->ulid(),
                    'token'        => $device->token,
                    'fid'          => $device->fid,
                    'platform'     => $device->platform->value,
                    'provider'     => $device->provider->value,
                    'environment'  => $device->environment->value,
                    'user_id'      => $device->userId,
                    'notification' => self::encode($notification),
                    'available_at' => $now,
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ],
            );
        } catch (\PDOException $e) {
            throw new RepositoryException(
                'Could not queue a push notification.',
                layer:    'repository.push.outbox',
                context:  ['token' => $device->masked()],
                previous: $e,
            );
        }
    }

    /**
     * Claim up to $limit due rows.
     *
     * CLAIMED, not merely selected: each row's attempt count is incremented and
     * its `available_at` pushed out before it is handed over, inside one
     * transaction. Two workers draining at once would otherwise both read the
     * same pending rows and both send them.
     *
     * This is a pessimistic claim and not a lock, so it is not airtight under
     * every driver — but it closes the window that matters (two workers on a
     * 100-row batch) and needs no `SELECT ... FOR UPDATE`, which SQLite does
     * not have.
     *
     * @return list<array<string, mixed>>
     */
    public function claim(int $limit, string $now, string $retryAt): array
    {
        try {
            $rows = $this->db->query(
                'SELECT id, public_id, token, fid, platform, provider, environment, user_id,
                        notification, attempts
                   FROM push_outbox
                  WHERE status = \'pending\' AND available_at <= :now
                  ORDER BY available_at ASC, id ASC
                  LIMIT ' . max(1, $limit),
                ['now' => $now],
            );

            if ($rows === []) {
                return [];
            }

            $ids          = [];
            $placeholders = [];

            foreach ($rows as $index => $row) {
                $placeholders[]           = ':i' . $index;
                $ids['i' . $index]        = (int) ($row['id'] ?? 0);
            }

            $this->db->execute(
                'UPDATE push_outbox
                    SET attempts = attempts + 1, available_at = :retry, updated_at = :now
                  WHERE id IN (' . implode(', ', $placeholders) . ')',
                [...$ids, 'retry' => $retryAt, 'now' => $now],
            );

            return array_values($rows);
        } catch (\PDOException $e) {
            throw new RepositoryException(
                'Could not claim push outbox rows.',
                layer:    'repository.push.outbox',
                previous: $e,
            );
        }
    }

    public function markDelivered(int $id, string $messageId, string $now): void
    {
        $this->finish($id, 'delivered', '', '', $messageId, $now);
    }

    /** A permanent refusal, or a dead token: it will not be tried again. */
    public function markFailed(int $id, string $reason, string $error, string $now): void
    {
        $this->finish($id, 'failed', $reason, $error, '', $now);
    }

    /** Attempts exhausted. Kept, so somebody can see it happened. */
    public function markDead(int $id, string $reason, string $error, string $now): void
    {
        $this->finish($id, 'dead', $reason, $error, '', $now);
    }

    /** Still pending, but record why the last attempt failed and when to retry. */
    public function reschedule(int $id, string $reason, string $error, string $availableAt, string $now): void
    {
        try {
            $this->db->execute(
                'UPDATE push_outbox
                    SET last_reason = :reason, last_error = :error,
                        available_at = :available, updated_at = :now
                  WHERE id = :id',
                [
                    'reason'    => substr($reason, 0, 64),
                    'error'     => substr($error, 0, 1000),
                    'available' => $availableAt,
                    'now'       => $now,
                    'id'        => $id,
                ],
            );
        } catch (\PDOException $e) {
            throw new RepositoryException(
                'Could not reschedule a push outbox row.',
                layer:    'repository.push.outbox',
                previous: $e,
            );
        }
    }

    /** @return array<string, int> status => count */
    public function countByStatus(): array
    {
        try {
            $rows = $this->db->query('SELECT status, COUNT(*) AS n FROM push_outbox GROUP BY status');
        } catch (\PDOException) {
            return [];
        }

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) ($row['status'] ?? '?')] = (int) ($row['n'] ?? 0);
        }

        return $counts;
    }

    /** How many pending rows are due right now. */
    public function countDue(string $now): int
    {
        try {
            $row = $this->db->queryOne(
                'SELECT COUNT(*) AS n FROM push_outbox
                  WHERE status = \'pending\' AND available_at <= :now',
                ['now' => $now],
            );
        } catch (\PDOException) {
            return 0;
        }

        return (int) ($row['n'] ?? 0);
    }

    /** Delete delivered rows older than $before. */
    public function prune(string $before): int
    {
        try {
            return $this->db->execute(
                'DELETE FROM push_outbox WHERE status = \'delivered\' AND delivered_at < :before',
                ['before' => $before],
            );
        } catch (\PDOException $e) {
            throw new RepositoryException(
                'Could not prune the push outbox.',
                layer:    'repository.push.outbox',
                previous: $e,
            );
        }
    }

    private function finish(
        int $id,
        string $status,
        string $reason,
        string $error,
        string $messageId,
        string $now,
    ): void {
        try {
            $this->db->execute(
                'UPDATE push_outbox
                    SET status = :status, last_reason = :reason, last_error = :error,
                        provider_message_id = :message_id, delivered_at = :delivered, updated_at = :now
                  WHERE id = :id',
                [
                    'status'     => $status,
                    'reason'     => substr($reason, 0, 64),
                    'error'      => substr($error, 0, 1000),
                    'message_id' => substr($messageId, 0, 128),
                    'delivered'  => $now,
                    'now'        => $now,
                    'id'         => $id,
                ],
            );
        } catch (\PDOException $e) {
            throw new RepositoryException(
                'Could not finish a push outbox row.',
                layer:    'repository.push.outbox',
                context:  ['id' => $id, 'status' => $status],
                previous: $e,
            );
        }
    }

    /**
     * Rebuild the device and notification a claimed row describes.
     *
     * @param array<string, mixed> $row
     * @return array{device: DeviceToken, notification: Notification}|null
     */
    public static function rehydrate(array $row): ?array
    {
        $platform = Platform::tryFrom((string) ($row['platform'] ?? ''));

        if ($platform === null) {
            return null;
        }

        try {
            $device = DeviceToken::of(
                token:       (string) ($row['token'] ?? ''),
                platform:    $platform,
                provider:    Provider::tryFrom((string) ($row['provider'] ?? '')) ?? $platform->provider(),
                environment: PushEnvironment::parse((string) ($row['environment'] ?? '')),
                userId:      (string) ($row['user_id'] ?? ''),
                fid:         (string) ($row['fid'] ?? ''),
            );

            $notification = self::decode((string) ($row['notification'] ?? ''));
        } catch (\Throwable) {
            return null;
        }

        return $notification === null ? null : ['device' => $device, 'notification' => $notification];
    }

    private static function encode(Notification $notification): string
    {
        return (string) json_encode([
            'title'      => $notification->title,
            'body'       => $notification->body,
            'data'       => $notification->data,
            'type'       => $notification->type->value,
            'priority'   => $notification->priority->value,
            'badge'      => $notification->badge,
            'sound'      => $notification->sound,
            'collapseId' => $notification->collapseId,
            'ttl'        => $notification->ttlSeconds,
            'topic'      => $notification->topic,
            'imageUrl'   => $notification->imageUrl,
        ], \JSON_UNESCAPED_UNICODE);
    }

    private static function decode(string $json): ?Notification
    {
        $decoded = json_decode($json, true);

        if (!\is_array($decoded)) {
            return null;
        }

        $data = $decoded['data'] ?? [];
        $clean = [];

        foreach (\is_array($data) ? $data : [] as $key => $value) {
            if (\is_string($key) && \is_string($value)) {
                $clean[$key] = $value;
            }
        }

        try {
            return Notification::of(
                title:      (string) ($decoded['title'] ?? ''),
                body:       (string) ($decoded['body'] ?? ''),
                data:       $clean,
                type:       PushType::tryFrom((string) ($decoded['type'] ?? '')) ?? PushType::Alert,
                priority:   \Plugins\Push\Domain\Priority::tryFrom((int) ($decoded['priority'] ?? 10)),
                // isset() already excludes null, so a `!== null` beside it is
                // dead — and the distinction matters here: an ABSENT badge
                // leaves the device's count alone, while 0 clears it.
                badge:      isset($decoded['badge']) ? (int) $decoded['badge'] : null,
                sound:      (string) ($decoded['sound'] ?? 'default'),
                collapseId: (string) ($decoded['collapseId'] ?? ''),
                ttlSeconds: isset($decoded['ttl']) ? (int) $decoded['ttl'] : null,
                topic:      (string) ($decoded['topic'] ?? ''),
                imageUrl:   (string) ($decoded['imageUrl'] ?? ''),
            );
        } catch (\Throwable) {
            return null;
        }
    }

    private function ulid(): string
    {
        $time     = (int) (microtime(true) * 1000);
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $out      = '';

        for ($i = 9; $i >= 0; $i--) {
            $out  = $alphabet[$time % 32] . $out;
            $time = intdiv($time, 32);
        }

        for ($i = 0; $i < 16; $i++) {
            $out .= $alphabet[random_int(0, 31)];
        }

        return $out;
    }
}
