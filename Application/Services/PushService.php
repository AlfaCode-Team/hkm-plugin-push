<?php

declare(strict_types=1);

namespace Plugins\Push\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\LoggerPort;
use Plugins\Push\API\Contracts\PushServiceContract;
use Plugins\Push\API\IntegrationEvents\PushTokenInvalidatedIntegrationEvent;
use Plugins\Push\Domain\Contracts\PushTransport;
use Plugins\Push\Domain\DeliveryOutcome;
use Plugins\Push\Domain\DeliveryReport;
use Plugins\Push\Domain\DeviceToken;
use Plugins\Push\Domain\Notification;
use Plugins\Push\Domain\Platform;
use Plugins\Push\Domain\Provider;
use Plugins\Push\Domain\PushEnvironment;
use Plugins\Push\Infrastructure\Config\PushConfig;
use Plugins\Push\Infrastructure\Persistence\DeviceRepository;
use Plugins\Push\Infrastructure\Persistence\OutboxRepository;

/**
 * The `push.notifications` use cases.
 *
 * ROUTING IS BY THE TOKEN'S PROVIDER, NOT THE DEVICE'S PLATFORM
 * ------------------------------------------------------------
 * An iOS app may register with APNs directly or through the Firebase SDK, and
 * the two tokens are not interchangeable. The platform says what the device is;
 * the provider says who issued the string. Routing on the platform would send
 * every Firebase-issued iOS token to Apple, which has never heard of it.
 */
final class PushService implements PushServiceContract
{
    public function __construct(
        private readonly PushConfig $config,
        private readonly DeviceRepository $devices,
        private readonly OutboxRepository $outbox,
        // Typed against the CONTRACT, not the two concrete gateways. A Service
        // may call a Gateway, but through a published interface — and the
        // practical payoff is that the outbox's retry and pruning behaviour can
        // be asserted without standing up a live APNs request to do it.
        private readonly ?PushTransport $apns = null,
        private readonly ?PushTransport $fcm = null,
        private readonly ?LoggerPort $logger = null,
        private readonly ?EventBus $events = null,
        private readonly ?\Closure $clock = null,
    ) {
    }

    public function registerDevice(
        string $token,
        Platform $platform,
        string $userId = '',
        ?PushEnvironment $environment = null,
        string $fid = '',
        string $label = '',
    ): bool {
        $device = DeviceToken::of(
            token:       $token,
            platform:    $platform,
            environment: $environment ?? $this->config->apnsEnvironment,
            userId:      $userId,
            fid:         $fid,
            label:       $label,
        );

        $reassigned = $this->devices->register($device, $this->timestamp());

        if ($reassigned) {
            // Logged at NOTICE rather than debug: a device changing hands is
            // the event that decides whether the next notification goes to the
            // right person, and it is the one thing an investigation into
            // "somebody else's notification appeared on my phone" needs to
            // find. The token is masked; the user ids are not, because the
            // whole value of the line is knowing who it moved between.
            $this->logger?->notice('push.device.reassigned', [
                'token'    => $device->masked(),
                'to'       => $device->userId,
                'platform' => $device->platform->value,
            ]);
        }

        return $reassigned;
    }

    public function forgetDevice(string $token): bool
    {
        return $this->devices->forget(trim($token));
    }

    public function forgetUser(string $userId): int
    {
        return $this->devices->forgetUser($userId);
    }

    public function notify(array $userIds, Notification $notification): int
    {
        $devices = $this->devices->forUsers(array_values(array_filter(
            array_map(static fn (mixed $id): string => \is_string($id) ? $id : '', $userIds),
        )));

        if ($devices === []) {
            // Worth a line. Zero devices and a successful send look identical
            // in every log, and "nobody has registered a device" is the single
            // most common reason a notification feature appears broken.
            $this->logger?->info('push.notify.no_devices', ['users' => \count($userIds)]);

            return 0;
        }

        $now = $this->timestamp();

        foreach ($devices as $device) {
            $this->outbox->queue($device, $notification, $now);
        }

        return \count($devices);
    }

    public function sendNow(string $token, Notification $notification): DeliveryReport
    {
        $owner = $this->devices->ownerOf(trim($token));

        if ($owner === null) {
            return DeliveryReport::refusedBeforeRouting(
                'not-registered',
                'No device is registered with that token, so there is nothing to send to. '
                . 'registerDevice() has to be called from the app before a notification can reach it.',
            );
        }

        $device = null;

        foreach ($this->devices->forUser($owner) as $candidate) {
            if ($candidate->token === trim($token)) {
                $device = $candidate;
                break;
            }
        }

        if ($device === null) {
            return DeliveryReport::refusedBeforeRouting(
                'unreadable',
                'The device row exists but could not be read back — most likely a platform or provider '
                . 'value this build does not recognise.',
            );
        }

        return $this->deliver($device, $notification);
    }

    /**
     * Send one notification to one device, now, and act on the answer.
     *
     * This is the ONLY place a token is pruned, so the rule lives in one
     * place: {@see DeliveryOutcome::TokenInvalid} deletes the registration and
     * announces it, and nothing else does.
     */
    public function deliver(DeviceToken $device, Notification $notification): DeliveryReport
    {
        $report = match ($device->provider) {
            Provider::Apns => $this->apns?->send($device, $notification),
            Provider::Fcm  => $this->fcm?->send($device, $notification),
        };

        if ($report === null) {
            return DeliveryReport::of(
                DeliveryOutcome::Rejected,
                $device->provider,
                'not-configured',
                \sprintf(
                    'This device needs %s, which is not configured. Set PUSH_%s_ENABLED=true and its '
                    . 'credentials, or the notification can never be delivered — `push:status` lists '
                    . 'what is missing.',
                    $device->provider->label(),
                    strtoupper($device->provider->value),
                ),
            );
        }

        if ($report->outcome->prunesToken()) {
            $this->devices->forget($device->token);

            $this->logger?->notice('push.token.invalidated', [
                'token'    => $device->masked(),
                'provider' => $device->provider->value,
                'reason'   => $report->reason,
            ]);

            // Dispatched AFTER the delete, never before — a listener that acts
            // on this (clearing a "notifications on" flag in a profile) must
            // not be told a token is gone while it is still there.
            $this->events?->dispatch(new PushTokenInvalidatedIntegrationEvent(
                userId:     $device->userId,
                platform:   $device->platform->value,
                provider:   $device->provider->value,
                reason:     $report->reason,
                occurredAt: (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339),
            ));
        }

        if ($report->isDelivered() && $report->reason !== 'skipped') {
            $this->devices->markDelivered($device->token, $this->timestamp());
        }

        if ($report->outcome->needsOperator()) {
            // A rejection is a configuration fault that no retry fixes, so it
            // is logged at WARNING with the provider's own explanation — the
            // alternative is a notification that fails identically forever
            // with nothing saying why.
            $this->logger?->warning('push.rejected', [
                'provider' => $device->provider->value,
                'reason'   => $report->reason,
                'detail'   => $report->detail,
            ]);
        }

        return $report;
    }

    public function drain(int $limit = 0): array
    {
        $limit = $limit > 0 ? $limit : $this->config->outboxBatch;
        $now   = $this->timestamp();

        // Claimed rows are pushed out by one minute so a crashed drain does not
        // leave them stuck, and a concurrent drain does not pick them up.
        $rows = $this->outbox->claim($limit, $now, $this->timestamp(60));

        $tally = ['queued' => \count($rows), 'delivered' => 0, 'retried' => 0, 'pruned' => 0, 'rejected' => 0];

        foreach ($rows as $row) {
            $id    = (int) ($row['id'] ?? 0);
            $parts = OutboxRepository::rehydrate($row);

            if ($parts === null) {
                $this->outbox->markFailed(
                    $id,
                    'unreadable',
                    'The queued row could not be rebuilt into a device and a notification. It was '
                    . 'probably written by a different version of this plugin.',
                    $now,
                );
                $tally['rejected']++;

                continue;
            }

            /** @var DeviceToken $device */
            $device = $parts['device'];
            /** @var Notification $notification */
            $notification = $parts['notification'];

            $report   = $this->deliver($device, $notification);
            $attempts = (int) ($row['attempts'] ?? 0);

            match (true) {
                $report->isDelivered() => $this->onDelivered($id, $report, $now, $tally),
                $report->outcome->prunesToken() => $this->onPruned($id, $report, $now, $tally),
                $report->outcome->isRetryable() => $this->onRetry($id, $report, $attempts, $now, $tally),
                default => $this->onRejected($id, $report, $now, $tally),
            };
        }

        return $tally;
    }

    /** @param array{queued:int,delivered:int,retried:int,pruned:int,rejected:int} $tally */
    private function onDelivered(int $id, DeliveryReport $report, string $now, array &$tally): void
    {
        $this->outbox->markDelivered($id, $report->providerMessageId, $now);
        $tally['delivered']++;
    }

    /** @param array{queued:int,delivered:int,retried:int,pruned:int,rejected:int} $tally */
    private function onPruned(int $id, DeliveryReport $report, string $now, array &$tally): void
    {
        // Not retried: the token is gone, so every future attempt fails the
        // same way, and the device row has already been deleted.
        $this->outbox->markFailed($id, $report->reason, $report->detail, $now);
        $tally['pruned']++;
    }

    /** @param array{queued:int,delivered:int,retried:int,pruned:int,rejected:int} $tally */
    private function onRetry(int $id, DeliveryReport $report, int $attempts, string $now, array &$tally): void
    {
        if ($attempts >= $this->config->outboxMaxAttempts) {
            $this->outbox->markDead(
                $id,
                $report->reason,
                \sprintf('Gave up after %d attempts. Last: %s', $attempts, $report->detail),
                $now,
            );
            $tally['rejected']++;

            return;
        }

        // The provider's own Retry-After wins over our backoff when it gave
        // one — Firebase asks for a minimum of 60s on a quota error, and
        // retrying sooner earns a longer limit.
        $backoff = max($report->retryAfter, self::backoffSeconds($attempts));

        $this->outbox->reschedule($id, $report->reason, $report->detail, $this->timestamp($backoff), $now);
        $tally['retried']++;
    }

    /** @param array{queued:int,delivered:int,retried:int,pruned:int,rejected:int} $tally */
    private function onRejected(int $id, DeliveryReport $report, string $now, array &$tally): void
    {
        $this->outbox->markFailed($id, $report->reason, $report->detail, $now);
        $tally['rejected']++;
    }

    /** Exponential, capped at an hour. */
    private static function backoffSeconds(int $attempts): int
    {
        return min(3600, 30 * (2 ** max(0, $attempts - 1)));
    }

    private function timestamp(int $plusSeconds = 0): string
    {
        $now = $this->clock !== null ? (int) ($this->clock)() : time();

        return gmdate('Y-m-d H:i:s', $now + $plusSeconds);
    }
}
