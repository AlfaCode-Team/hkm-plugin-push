<?php

declare(strict_types=1);

namespace Plugins\Push\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Push\Application\Services\PushService;
use Plugins\Push\Domain\Notification;
use Plugins\Push\Domain\Platform;
use Plugins\Push\Infrastructure\Config\PushConfig;
use Plugins\Push\Infrastructure\Persistence\DeviceRepository;
use Plugins\Push\Infrastructure\Persistence\OutboxRepository;
use Plugins\Push\Tests\Support\ArrayDatabase;

/**
 * The privacy bug this plugin is shaped to prevent.
 *
 * A device token identifies a DEVICE, and no push network changes it when
 * somebody signs out. So on a shared handset:
 *
 *   1. user A signs in — token T is registered to A
 *   2. user A signs out — the token is still T
 *   3. user B signs in — the app registers T again
 *
 * If step 3 adds a row, every notification for A is delivered to the phone B is
 * holding. Successfully. With no error. Only on shared devices, so never on a
 * developer's own.
 *
 * These tests are the reason `push_devices.token` carries a unique index and
 * `register()` is an upsert. Both halves are exercised: the constraint is real
 * (SQLite enforces it here) and the SQL targets it.
 */
#[CoversClass(PushService::class)]
#[CoversClass(DeviceRepository::class)]
final class SharedDeviceTest extends TestCase
{
    private const TOKEN = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    private ArrayDatabase $db;
    private PushService $push;

    protected function setUp(): void
    {
        $this->db = new ArrayDatabase();

        $this->push = new PushService(
            config:  self::config(),
            devices: new DeviceRepository($this->db),
            outbox:  new OutboxRepository($this->db),
        );
    }

    private static function config(): PushConfig
    {
        $_ENV['PUSH_ENABLED'] = 'true';

        return PushConfig::fromEnv();
    }

    public function test_the_same_device_registered_for_a_second_user_is_REASSIGNED_not_duplicated(): void
    {
        $this->push->registerDevice(self::TOKEN, Platform::Ios, 'user-A');
        $this->push->registerDevice(self::TOKEN, Platform::Ios, 'user-B');

        $rows = $this->db->rows('push_devices');

        self::assertCount(
            1,
            $rows,
            'two rows would mean every notification for user A is also delivered to the handset '
            . 'user B is now holding — successfully, and with no error anywhere',
        );
        self::assertSame('user-B', $rows[0]['user_id']);
    }

    public function test_the_reassignment_is_reported_to_the_caller(): void
    {
        self::assertFalse(
            $this->push->registerDevice(self::TOKEN, Platform::Ios, 'user-A'),
            'a first registration changes no owner',
        );

        self::assertTrue(
            $this->push->registerDevice(self::TOKEN, Platform::Ios, 'user-B'),
            'the caller has to be able to log that a shared device changed hands',
        );
    }

    public function test_re_registering_the_same_user_is_not_a_reassignment(): void
    {
        $this->push->registerDevice(self::TOKEN, Platform::Ios, 'user-A');

        self::assertFalse(
            $this->push->registerDevice(self::TOKEN, Platform::Ios, 'user-A'),
            'an app re-registering on every launch is the intended usage and must stay quiet',
        );
    }

    public function test_a_notification_for_the_previous_owner_reaches_nobody(): void
    {
        $this->push->registerDevice(self::TOKEN, Platform::Ios, 'user-A');
        $this->push->registerDevice(self::TOKEN, Platform::Ios, 'user-B');

        $queued = $this->push->notify(['user-A'], Notification::of(title: 'For A only'));

        self::assertSame(
            0,
            $queued,
            'user A no longer has a registered device, so there is nothing to queue — this is the '
            . 'whole point: the notification is LOST rather than misdelivered',
        );
    }

    public function test_the_new_owner_does_receive_their_own(): void
    {
        $this->push->registerDevice(self::TOKEN, Platform::Ios, 'user-A');
        $this->push->registerDevice(self::TOKEN, Platform::Ios, 'user-B');

        self::assertSame(1, $this->push->notify(['user-B'], Notification::of(title: 'For B')));
    }

    public function test_forgetting_on_sign_out_removes_the_registration(): void
    {
        $this->push->registerDevice(self::TOKEN, Platform::Ios, 'user-A');

        self::assertTrue($this->push->forgetDevice(self::TOKEN));
        self::assertSame([], $this->db->rows('push_devices'));
        self::assertSame(0, $this->push->notify(['user-A'], Notification::of(title: 'gone')));
    }

    public function test_forgetting_an_unregistered_token_is_harmless(): void
    {
        self::assertFalse($this->push->forgetDevice('never-registered'));
    }

    public function test_sign_out_everywhere_removes_every_device_for_that_user(): void
    {
        $this->push->registerDevice(self::TOKEN, Platform::Ios, 'user-A');
        $this->push->registerDevice(str_repeat('b', 64), Platform::Ios, 'user-A');
        $this->push->registerDevice(str_repeat('c', 64), Platform::Ios, 'user-B');

        self::assertSame(2, $this->push->forgetUser('user-A'));
        self::assertCount(1, $this->db->rows('push_devices'), 'user B keeps theirs');
    }

    public function test_a_re_registration_keeps_the_public_id(): void
    {
        $this->push->registerDevice(self::TOKEN, Platform::Ios, 'user-A');
        $first = $this->db->rows('push_devices')[0]['public_id'];

        $this->push->registerDevice(self::TOKEN, Platform::Ios, 'user-A', label: 'iPhone 15');

        self::assertSame(
            $first,
            $this->db->rows('push_devices')[0]['public_id'],
            'an application may hold a reference to the public id; a re-registration is the same '
            . 'device and must not invalidate it',
        );
        self::assertSame('iPhone 15', $this->db->rows('push_devices')[0]['label'], 'but the label updates');
    }

    public function test_an_android_device_and_an_ios_device_coexist(): void
    {
        $this->push->registerDevice(self::TOKEN, Platform::Ios, 'user-A');
        $this->push->registerDevice('fcm-token-for-the-same-person', Platform::Android, 'user-A');

        self::assertSame(
            2,
            $this->push->notify(['user-A'], Notification::of(title: 'both')),
            'fan-out is per DEVICE: one person with two handsets is two notifications, because '
            . 'FCM HTTP v1 has no multicast',
        );
    }
}
