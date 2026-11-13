<?php

declare(strict_types=1);

namespace Plugins\Push\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Push\Application\Services\PushService;
use Plugins\Push\Domain\Contracts\PushTransport;
use Plugins\Push\Domain\DeliveryOutcome;
use Plugins\Push\Domain\DeliveryReport;
use Plugins\Push\Domain\DeviceToken;
use Plugins\Push\Domain\Notification;
use Plugins\Push\Domain\Platform;
use Plugins\Push\Domain\Provider;
use Plugins\Push\Infrastructure\Config\PushConfig;
use Plugins\Push\Infrastructure\Persistence\DeviceRepository;
use Plugins\Push\Infrastructure\Persistence\OutboxRepository;
use Plugins\Push\Tests\Support\ArrayDatabase;

/**
 * The outbox: one row per DEVICE, retried on its own schedule.
 *
 * The unit matters. A queued JOB carries "notify these users", so re-running it
 * after a partial failure re-sends to everyone who already succeeded — and a
 * push cannot be taken back. A row per device is the smallest thing that can be
 * retried without duplicating.
 */
#[CoversClass(PushService::class)]
#[CoversClass(OutboxRepository::class)]
final class OutboxTest extends TestCase
{
    private const TOKEN = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    private ArrayDatabase $db;

    protected function setUp(): void
    {
        $this->db = new ArrayDatabase();

        foreach (['PUSH_ENABLED' => 'true', 'PUSH_OUTBOX_MAX_ATTEMPTS' => '3', 'PUSH_DRY_RUN' => 'false'] as $k => $v) {
            $_ENV[$k] = $v;
        }
    }

    /**
     * A service whose transport answers are scripted.
     *
     * The real `ApnsGateway` is swapped for a `PushTransport` double, which is
     * exactly what that interface exists for: the behaviour under test is the
     * OUTBOX's — attempts, backoff, terminal states — and routing it through a
     * live APNs request would make these tests about HTTP instead.
     */
    private function service(DeliveryReport ...$answers): PushService
    {
        /** @var list<DeliveryReport> $answers */
        $transport = new class ($answers) implements PushTransport {
            /** @var list<DeliveryReport> */
            private array $answers;
            public int $calls = 0;

            /** @param list<DeliveryReport> $answers */
            public function __construct(array $answers)
            {
                $this->answers = $answers;
            }

            public function send(DeviceToken $device, Notification $notification): DeliveryReport
            {
                $this->calls++;

                return array_shift($this->answers)
                    ?? DeliveryReport::delivered(Provider::Apns, 'default');
            }
        };

        return new PushService(
            config:  PushConfig::fromEnv(),
            devices: new DeviceRepository($this->db),
            outbox:  new OutboxRepository($this->db),
            apns:    $transport,
            fcm:     $transport,
        );
    }

    private function queueOne(PushService $push): void
    {
        $push->registerDevice(self::TOKEN, Platform::Ios, 'user-1');
        self::assertSame(1, $push->notify(['user-1'], Notification::of(title: 'Hi')));
    }

    public function test_a_delivered_row_is_marked_and_not_retried(): void
    {
        $push = $this->service(DeliveryReport::delivered(Provider::Apns, 'apns-id-1'));
        $this->queueOne($push);

        $tally = $push->drain();

        self::assertSame(1, $tally['delivered']);

        $row = $this->db->rows('push_outbox')[0];
        self::assertSame('delivered', $row['status']);
        self::assertSame('apns-id-1', $row['provider_message_id']);

        self::assertSame(['queued' => 0, 'delivered' => 0, 'retried' => 0, 'pruned' => 0, 'rejected' => 0],
            $push->drain(), 'a delivered row must never be picked up again');
    }

    public function test_a_transient_failure_is_rescheduled_with_backoff(): void
    {
        $push = $this->service(DeliveryReport::unreachable(Provider::Apns, 'connection refused'));
        $this->queueOne($push);

        $tally = $push->drain();

        self::assertSame(1, $tally['retried']);

        $row = $this->db->rows('push_outbox')[0];
        self::assertSame('pending', $row['status']);
        self::assertSame(1, (int) $row['attempts']);
        self::assertStringContainsString('connection refused', (string) $row['last_error']);
        self::assertGreaterThan(
            gmdate('Y-m-d H:i:s'),
            (string) $row['available_at'],
            'rescheduled into the future, or the next drain would hammer the provider',
        );
    }

    public function test_attempts_are_exhausted_rather_than_retried_forever(): void
    {
        $push = $this->service(...array_fill(
            0,
            5,
            DeliveryReport::unreachable(Provider::Apns, 'still down'),
        ));
        $this->queueOne($push);

        // PUSH_OUTBOX_MAX_ATTEMPTS is 3. Each drain claims the row (which
        // increments attempts) and the backoff is bypassed by resetting
        // available_at, so the cap is what stops it.
        for ($i = 0; $i < 5; $i++) {
            $this->db->execute('UPDATE push_outbox SET available_at = :past', ['past' => '2000-01-01 00:00:00']);
            $push->drain();
        }

        $row = $this->db->rows('push_outbox')[0];

        self::assertSame('dead', $row['status']);
        self::assertStringContainsString('Gave up after', (string) $row['last_error']);
    }

    public function test_a_dead_token_is_not_retried(): void
    {
        $push = $this->service(DeliveryReport::of(
            DeliveryOutcome::TokenInvalid,
            Provider::Apns,
            'Unregistered',
            'the app was uninstalled',
        ));
        $this->queueOne($push);

        $tally = $push->drain();

        self::assertSame(1, $tally['pruned']);
        self::assertSame(
            'failed',
            $this->db->rows('push_outbox')[0]['status'],
            'the token is gone, so every future attempt fails identically',
        );
    }

    public function test_a_rejection_is_terminal_and_keeps_the_reason(): void
    {
        $push = $this->service(DeliveryReport::of(
            DeliveryOutcome::Rejected,
            Provider::Apns,
            'TopicDisallowed',
            'this topic is not allowed for this key',
        ));
        $this->queueOne($push);

        $tally = $push->drain();

        self::assertSame(1, $tally['rejected']);

        $row = $this->db->rows('push_outbox')[0];
        self::assertSame('failed', $row['status']);
        self::assertSame('TopicDisallowed', $row['last_reason']);
    }

    public function test_the_providers_retry_after_beats_our_own_backoff(): void
    {
        $push = $this->service(DeliveryReport::of(
            DeliveryOutcome::Retry,
            Provider::Fcm,
            'QUOTA_EXCEEDED',
            'rate limited',
            429,
            3600,
        ));
        $this->queueOne($push);
        $push->drain();

        $available = (string) $this->db->rows('push_outbox')[0]['available_at'];

        self::assertGreaterThan(
            gmdate('Y-m-d H:i:s', time() + 1800),
            $available,
            'Firebase asks for a minimum delay and retrying sooner earns a longer limit, so the '
            . 'provider\'s number has to win',
        );
    }

    public function test_claiming_increments_attempts_so_two_drains_do_not_both_send(): void
    {
        $push = $this->service(
            DeliveryReport::unreachable(Provider::Apns, 'down'),
        );
        $this->queueOne($push);

        $push->drain();

        // The row was pushed into the future by the claim, so an immediate
        // second drain finds nothing — which is what stops two workers
        // delivering the same notification twice.
        self::assertSame(0, $push->drain()['queued']);
    }

    public function test_an_unreadable_row_fails_rather_than_blocking_the_queue(): void
    {
        $push = $this->service();
        $this->queueOne($push);

        // A row written by a different version of the plugin.
        $this->db->execute("UPDATE push_outbox SET platform = 'smoke-signals'");

        $tally = $push->drain();

        self::assertSame(1, $tally['rejected']);
        self::assertSame('failed', $this->db->rows('push_outbox')[0]['status']);
        self::assertStringContainsString('could not be rebuilt', (string) $this->db->rows('push_outbox')[0]['last_error']);
    }

    public function test_the_notification_survives_the_round_trip(): void
    {
        $push = $this->service();
        $push->registerDevice(self::TOKEN, Platform::Ios, 'user-1');
        $push->notify(['user-1'], Notification::of(
            title: 'Order shipped',
            body: 'It is on the way',
            data: ['orderId' => '42'],
            badge: 3,
            collapseId: 'order-42',
            ttlSeconds: 600,
        ));

        $row   = $this->db->rows('push_outbox')[0];
        $parts = OutboxRepository::rehydrate($row);

        self::assertNotNull($parts);
        /** @var Notification $notification */
        $notification = $parts['notification'];

        self::assertSame('Order shipped', $notification->title);
        self::assertSame(['orderId' => '42'], $notification->data);
        self::assertSame(3, $notification->badge);
        self::assertSame('order-42', $notification->collapseId);
        self::assertSame(600, $notification->ttlSeconds);
    }

    public function test_nothing_due_is_not_an_error(): void
    {
        self::assertSame(
            ['queued' => 0, 'delivered' => 0, 'retried' => 0, 'pruned' => 0, 'rejected' => 0],
            $this->service()->drain(),
        );
    }
}
