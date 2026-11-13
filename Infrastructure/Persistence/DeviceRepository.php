<?php

declare(strict_types=1);

namespace Plugins\Push\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\Push\Domain\DeviceToken;
use Plugins\Push\Domain\Platform;
use Plugins\Push\Domain\Provider;
use Plugins\Push\Domain\PushEnvironment;

/**
 * The registered devices.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * register() IS AN UPSERT ON THE TOKEN, AND THAT IS THE POINT
 * ────────────────────────────────────────────────────────────────────────────
 * One token, one owner. A device token identifies a DEVICE, and no push network
 * changes it when a user signs out — so when a second person signs in on the
 * same handset, the app re-registers the SAME token and that must REPLACE the
 * previous owner.
 *
 * An insert here would leave the token mapped to both users, and every
 * notification for the first would be delivered to the phone the second is
 * holding. Successfully. With no error, on either side. Only on shared devices,
 * so never in development.
 *
 * `$db->upsert()` is used rather than a hand-written ON DUPLICATE KEY / ON
 * CONFLICT, because the kernel's port compiles the right one per driver and
 * this plugin must work on MySQL, PostgreSQL and SQLite alike.
 */
final class DeviceRepository
{
    public function __construct(private readonly DatabasePort $db)
    {
    }

    /**
     * Register or re-register a device.
     *
     * Returns true when this call CHANGED the owner — which is the event worth
     * logging, because it is the sign a shared device just changed hands.
     */
    public function register(DeviceToken $device, string $now): bool
    {
        $previousOwner = $this->ownerOf($device->token);

        try {
            $this->db->upsert(
                'push_devices',
                [
                    'public_id'    => $this->ulid(),
                    'token'        => $device->token,
                    'fid'          => $device->fid,
                    'platform'     => $device->platform->value,
                    'provider'     => $device->provider->value,
                    'environment'  => $device->environment->value,
                    'user_id'      => $device->userId,
                    'label'        => $device->label,
                    'failure_count' => 0,
                    'last_seen_at' => $now,
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ],
                ['token'],
                // public_id and created_at are NOT updated: a re-registration
                // is the same device, and rewriting its public id would break
                // any reference an application holds to it.
                ['fid', 'platform', 'provider', 'environment', 'user_id', 'label',
                 'failure_count', 'last_seen_at', 'updated_at'],
            );
        } catch (\PDOException $e) {
            throw new RepositoryException(
                'Could not register a push device.',
                layer:   'repository.push.device',
                context: ['token' => $device->masked(), 'platform' => $device->platform->value],
                previous: $e,
            );
        }

        return $previousOwner !== null
            && $previousOwner !== ''
            && $previousOwner !== $device->userId;
    }

    /**
     * Every device for one user.
     *
     * @return list<DeviceToken>
     */
    public function forUser(string $userId): array
    {
        if (trim($userId) === '') {
            return [];
        }

        try {
            $rows = $this->db->query(
                'SELECT token, fid, platform, provider, environment, user_id, label
                   FROM push_devices
                  WHERE user_id = :user
                  ORDER BY last_seen_at DESC',
                ['user' => trim($userId)],
            );
        } catch (\PDOException $e) {
            throw new RepositoryException(
                'Could not read push devices for a user.',
                layer:    'repository.push.device',
                context:  ['userId' => $userId],
                previous: $e,
            );
        }

        return array_values(array_filter(array_map(self::hydrate(...), $rows)));
    }

    /**
     * @param list<string> $userIds
     * @return list<DeviceToken>
     */
    public function forUsers(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('trim', $userIds))));

        if ($userIds === []) {
            return [];
        }

        // Named placeholders built per id: the port takes an array of bindings,
        // not a list, so an IN clause has to be expanded. Count-bounded, never
        // interpolated with a value.
        $placeholders = [];
        $bindings     = [];

        foreach ($userIds as $index => $userId) {
            $placeholders[]        = ':u' . $index;
            $bindings['u' . $index] = $userId;
        }

        try {
            $rows = $this->db->query(
                'SELECT token, fid, platform, provider, environment, user_id, label
                   FROM push_devices
                  WHERE user_id IN (' . implode(', ', $placeholders) . ')
                  ORDER BY last_seen_at DESC',
                $bindings,
            );
        } catch (\PDOException $e) {
            throw new RepositoryException(
                'Could not read push devices for a set of users.',
                layer:    'repository.push.device',
                context:  ['users' => \count($userIds)],
                previous: $e,
            );
        }

        return array_values(array_filter(array_map(self::hydrate(...), $rows)));
    }

    /** Who this token currently belongs to, or null when it is not registered. */
    public function ownerOf(string $token): ?string
    {
        if ($token === '') {
            return null;
        }

        try {
            $row = $this->db->queryOne(
                'SELECT user_id FROM push_devices WHERE token = :token',
                ['token' => $token],
            );
        } catch (\PDOException $e) {
            throw new RepositoryException(
                'Could not look up a push device owner.',
                layer:    'repository.push.device',
                previous: $e,
            );
        }

        return $row === null ? null : (string) ($row['user_id'] ?? '');
    }

    /**
     * Remove a token.
     *
     * Called on sign-out and when a provider reports the token dead. A hard
     * delete, not a soft one: a dead token has no history worth keeping, and a
     * soft-deleted row would still have to be excluded from every fan-out
     * query — one `WHERE` away from being delivered to anyway.
     */
    public function forget(string $token): bool
    {
        if ($token === '') {
            return false;
        }

        try {
            return $this->db->execute(
                'DELETE FROM push_devices WHERE token = :token',
                ['token' => $token],
            ) > 0;
        } catch (\PDOException $e) {
            throw new RepositoryException(
                'Could not remove a push device.',
                layer:    'repository.push.device',
                previous: $e,
            );
        }
    }

    /** Every device belonging to a user — the sign-out case for one person. */
    public function forgetUser(string $userId): int
    {
        if (trim($userId) === '') {
            return 0;
        }

        try {
            return $this->db->execute(
                'DELETE FROM push_devices WHERE user_id = :user',
                ['user' => trim($userId)],
            );
        } catch (\PDOException $e) {
            throw new RepositoryException(
                'Could not remove a user\'s push devices.',
                layer:    'repository.push.device',
                previous: $e,
            );
        }
    }

    public function markDelivered(string $token, string $now): void
    {
        try {
            $this->db->execute(
                'UPDATE push_devices
                    SET last_delivered_at = :now, failure_count = 0, updated_at = :now2
                  WHERE token = :token',
                ['now' => $now, 'now2' => $now, 'token' => $token],
            );
        } catch (\PDOException) {
            // Deliberately swallowed. This is bookkeeping AFTER a notification
            // the provider has already accepted — failing the delivery because
            // a statistics column could not be written would turn a success
            // into a retry, and the retry would deliver it a second time.
        }
    }

    public function countAll(): int
    {
        try {
            $row = $this->db->queryOne('SELECT COUNT(*) AS n FROM push_devices');
        } catch (\PDOException) {
            return 0;
        }

        return (int) ($row['n'] ?? 0);
    }

    /** @return array<string, int> platform => count */
    public function countByPlatform(): array
    {
        try {
            $rows = $this->db->query('SELECT platform, COUNT(*) AS n FROM push_devices GROUP BY platform');
        } catch (\PDOException) {
            return [];
        }

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) ($row['platform'] ?? '?')] = (int) ($row['n'] ?? 0);
        }

        return $counts;
    }

    /** Devices not re-registered since $before — candidates for pruning. */
    public function countStale(string $before): int
    {
        try {
            $row = $this->db->queryOne(
                'SELECT COUNT(*) AS n FROM push_devices
                  WHERE last_seen_at IS NULL OR last_seen_at < :before',
                ['before' => $before],
            );
        } catch (\PDOException) {
            return 0;
        }

        return (int) ($row['n'] ?? 0);
    }

    public function pruneStale(string $before): int
    {
        try {
            return $this->db->execute(
                'DELETE FROM push_devices WHERE last_seen_at IS NULL OR last_seen_at < :before',
                ['before' => $before],
            );
        } catch (\PDOException $e) {
            throw new RepositoryException(
                'Could not prune stale push devices.',
                layer:    'repository.push.device',
                previous: $e,
            );
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): ?DeviceToken
    {
        $platform = Platform::tryFrom((string) ($row['platform'] ?? ''));

        if ($platform === null) {
            // A row written by a newer version of this plugin, or by hand. It
            // is skipped rather than fatal: one unreadable registration must
            // not stop a fan-out to every other device the user owns.
            return null;
        }

        try {
            return DeviceToken::of(
                token:       (string) ($row['token'] ?? ''),
                platform:    $platform,
                provider:    Provider::tryFrom((string) ($row['provider'] ?? '')) ?? $platform->provider(),
                environment: PushEnvironment::parse((string) ($row['environment'] ?? '')),
                userId:      (string) ($row['user_id'] ?? ''),
                fid:         (string) ($row['fid'] ?? ''),
                label:       (string) ($row['label'] ?? ''),
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /** A lexicographically sortable public id. */
    private function ulid(): string
    {
        $time = (int) (microtime(true) * 1000);
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $out = '';

        for ($i = 9; $i >= 0; $i--) {
            $out = $alphabet[$time % 32] . $out;
            $time = intdiv($time, 32);
        }

        for ($i = 0; $i < 16; $i++) {
            $out .= $alphabet[random_int(0, 31)];
        }

        return $out;
    }
}
