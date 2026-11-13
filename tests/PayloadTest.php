<?php

declare(strict_types=1);

namespace Plugins\Push\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Push\Domain\ApnsPayload;
use Plugins\Push\Domain\DeviceToken;
use Plugins\Push\Domain\Exceptions\PushException;
use Plugins\Push\Domain\FcmMessage;
use Plugins\Push\Domain\Notification;
use Plugins\Push\Domain\Platform;
use Plugins\Push\Domain\Priority;
use Plugins\Push\Domain\Provider;
use Plugins\Push\Domain\PushType;

/**
 * Payload shaping, and the contradictions refused before they reach a provider.
 *
 * Every rule here has the same justification: Apple's warning that a push type
 * which does not match its payload may produce "an error, delay the delivery of
 * the notification, or drop it altogether". Two of those three are silent, so
 * the only place these can be caught usefully is at the call site.
 */
#[CoversClass(Notification::class)]
#[CoversClass(ApnsPayload::class)]
#[CoversClass(FcmMessage::class)]
final class PayloadTest extends TestCase
{
    // ── Notification rules ──────────────────────────────────────────────────

    public function test_a_background_push_carrying_an_alert_is_refused(): void
    {
        $this->expectException(PushException::class);
        $this->expectExceptionMessage('drop it altogether');

        Notification::of(title: 'Hello', type: PushType::Background);
    }

    public function test_a_background_push_takes_priority_5_without_being_asked(): void
    {
        // FCM states the rule from its side: "For data-only iOS messages, only
        // normal priority (apns-priority '5') is allowed."
        self::assertSame(Priority::Normal, Notification::silent(['a' => 'b'])->priority);
    }

    public function test_a_background_push_EXPLICITLY_at_priority_10_is_refused(): void
    {
        $this->expectException(PushException::class);
        $this->expectExceptionMessage('must be sent at priority 5');

        Notification::of(data: ['a' => 'b'], type: PushType::Background, priority: Priority::Immediate);
    }

    public function test_an_empty_notification_is_refused(): void
    {
        $this->expectException(PushException::class);
        $this->expectExceptionMessage('PayloadEmpty');

        Notification::of();
    }

    public function test_an_over_long_collapse_id_is_refused_with_the_limit(): void
    {
        $this->expectException(PushException::class);
        $this->expectExceptionMessage('64');

        Notification::of(title: 'x', collapseId: str_repeat('c', 65));
    }

    public function test_a_reserved_fcm_data_key_is_refused_by_name(): void
    {
        $this->expectException(PushException::class);
        $this->expectExceptionMessage('gcm.notification.title');

        Notification::of(title: 'x', data: ['gcm.notification.title' => 'y']);
    }

    public function test_from_and_message_type_are_reserved_too(): void
    {
        foreach (['from', 'message_type', 'google.foo'] as $key) {
            try {
                Notification::of(title: 'x', data: [$key => 'y']);
                self::fail("data[{$key}] should have been refused");
            } catch (PushException $e) {
                self::assertStringContainsString('reserve', $e->getMessage());
            }
        }
    }

    public function test_a_negative_badge_is_refused_and_zero_is_allowed(): void
    {
        self::assertSame(0, Notification::of(title: 'x', badge: 0)->badge, '0 clears the badge');
        self::assertNull(Notification::of(title: 'x')->badge, 'absent leaves it alone');

        $this->expectException(PushException::class);
        Notification::of(title: 'x', badge: -1);
    }

    public function test_ttl_becomes_an_absolute_expiration_as_late_as_possible(): void
    {
        $notification = Notification::of(title: 'x', ttlSeconds: 600);

        // Resolved against the time of SENDING, not of construction: a row that
        // waited ten minutes in the outbox must still expire when the sender
        // meant.
        self::assertSame(1_700_000_600, $notification->expirationAt(1_700_000_000));
        self::assertNull(Notification::of(title: 'x')->expirationAt(1_700_000_000));
    }

    // ── APNs payload placement ──────────────────────────────────────────────

    public function test_custom_keys_are_SIBLINGS_of_aps_not_children(): void
    {
        $payload = json_decode(
            ApnsPayload::build(Notification::of(title: 'Hi', data: ['orderId' => '42']))->json,
            true,
        );

        self::assertSame('42', $payload['orderId'] ?? null, 'a custom key belongs at the top level');
        self::assertArrayNotHasKey(
            'orderId',
            $payload['aps'],
            'inside aps it is in Apple\'s reserved namespace, so it is ignored — the notification '
            . 'still displays perfectly and the data simply never arrives',
        );
    }

    public function test_data_named_aps_is_refused(): void
    {
        $this->expectException(PushException::class);
        $this->expectExceptionMessage('reserved dictionary');

        ApnsPayload::build(Notification::of(title: 'x', data: ['aps' => 'y']));
    }

    public function test_a_background_push_is_content_available_and_nothing_else(): void
    {
        $aps = json_decode(ApnsPayload::build(Notification::silent(['sync' => '1']))->json, true)['aps'];

        self::assertSame(1, $aps['content-available'] ?? null, 'hyphenated; contentAvailable is ignored');
        self::assertArrayNotHasKey('alert', $aps);
        self::assertArrayNotHasKey('sound', $aps, 'a silent push does not make a noise');
    }

    public function test_an_image_sets_mutable_content_or_the_extension_never_runs(): void
    {
        $payload = json_decode(
            ApnsPayload::build(Notification::of(title: 'x', imageUrl: 'https://e.test/a.png'))->json,
            true,
        );

        self::assertSame(1, $payload['aps']['mutable-content'] ?? null);
        self::assertSame('https://e.test/a.png', $payload['image_url'] ?? null);
    }

    public function test_the_size_limit_is_measured_on_the_ENCODED_bytes(): void
    {
        // JSON escaping is the point: a string of quote marks doubles in
        // length once encoded, so measuring the input would pass a payload the
        // provider then refuses with 413 after taking the TLS handshake.
        $quotes = str_repeat('"', 3000);

        try {
            ApnsPayload::build(Notification::of(title: 'x', data: ['q' => $quotes]));
            self::fail('a payload that encodes past 4096 bytes should be refused');
        } catch (PushException $e) {
            self::assertStringContainsString('4096', $e->getMessage());
            self::assertStringContainsString('must not use a compressed JSON payload', $e->getMessage());
        }
    }

    public function test_a_payload_just_inside_the_limit_is_accepted(): void
    {
        $payload = ApnsPayload::build(Notification::of(title: 'x', data: ['p' => str_repeat('a', 3900)]));

        self::assertLessThanOrEqual(4096, $payload->bytes);
        self::assertGreaterThan(3900, $payload->bytes);
    }

    public function test_voip_gets_the_larger_ceiling(): void
    {
        $payload = ApnsPayload::build(Notification::of(
            data: ['p' => str_repeat('a', 4500)],
            type: PushType::Voip,
        ));

        self::assertGreaterThan(4096, $payload->bytes, 'this would be refused as a normal push');
        self::assertLessThanOrEqual(5120, $payload->bytes);
    }

    // ── FCM message shaping ─────────────────────────────────────────────────

    public function test_fcm_prefers_fid_over_the_deprecated_token_field(): void
    {
        $device = DeviceToken::of(
            token: 'reg-token',
            platform: Platform::Android,
            fid: 'installation-id',
        );

        $message = json_decode(
            FcmMessage::build($device, Notification::of(title: 'x'), 'com.example.app')->json,
            true,
        )['message'];

        self::assertSame('installation-id', $message['fid'] ?? null);
        self::assertArrayNotHasKey(
            'token',
            $message,
            'Firebase marks the token target deprecated: "Use fid instead"',
        );
    }

    public function test_fcm_falls_back_to_token_when_there_is_no_fid(): void
    {
        $device  = DeviceToken::of(token: 'reg-token', platform: Platform::Android);
        $message = json_decode(
            FcmMessage::build($device, Notification::of(title: 'x'), 'com.example.app')->json,
            true,
        )['message'];

        self::assertSame('reg-token', $message['token'] ?? null);
        self::assertArrayNotHasKey('fid', $message);
    }

    public function test_the_android_ttl_is_a_duration_STRING(): void
    {
        $device  = DeviceToken::of(token: 't', platform: Platform::Android);
        $message = json_decode(
            FcmMessage::build($device, Notification::of(title: 'x', ttlSeconds: 600), 'app')->json,
            true,
        )['message'];

        self::assertSame(
            '600s',
            $message['android']['ttl'] ?? null,
            'a protobuf Duration is a string with a trailing s; an integer is INVALID_ARGUMENT',
        );
    }

    public function test_the_apns_block_is_sent_even_on_the_fcm_path(): void
    {
        // Without it, FCM translates to APNs using ITS defaults, so the same
        // Notification behaves differently depending on which route it took.
        //
        // The provider is stated explicitly: an iOS app that registers through
        // the Firebase SDK holds an FCM token, not an APNs one, and the two are
        // not interchangeable. Leaving it implicit makes DeviceToken apply the
        // APNs hex rule and refuse the registration — correctly.
        $device  = DeviceToken::of(token: 't', platform: Platform::Ios, provider: Provider::Fcm);
        $message = json_decode(
            FcmMessage::build($device, Notification::silent(['a' => 'b']), 'com.example.app')->json,
            true,
        )['message'];

        self::assertSame('background', $message['apns']['headers']['apns-push-type'] ?? null);
        self::assertSame('5', $message['apns']['headers']['apns-priority'] ?? null);
        self::assertSame('com.example.app', $message['apns']['headers']['apns-topic'] ?? null);
        self::assertSame(1, $message['apns']['payload']['aps']['content-available'] ?? null);
    }

    public function test_a_live_activity_gets_apples_documented_topic_suffix(): void
    {
        $device  = DeviceToken::of(token: 't', platform: Platform::Ios, provider: Provider::Fcm);
        $message = json_decode(
            FcmMessage::build($device, Notification::of(title: 'x', type: PushType::LiveActivity), 'com.e.app')
                ->json,
            true,
        )['message'];

        self::assertSame(
            'com.e.app.push-type.liveactivity',
            $message['apns']['headers']['apns-topic'] ?? null,
        );
    }

    public function test_an_explicit_topic_overrides_the_configured_one(): void
    {
        $device  = DeviceToken::of(token: 't', platform: Platform::Ios, provider: Provider::Fcm);
        $message = json_decode(
            FcmMessage::build(
                $device,
                Notification::of(title: 'x', type: PushType::Voip, topic: 'com.e.app.voip'),
                'com.e.app',
            )->json,
            true,
        )['message'];

        // VoIP's suffix is deliberately NOT asserted by this plugin, so the
        // caller supplies the whole topic.
        self::assertSame('com.e.app.voip', $message['apns']['headers']['apns-topic'] ?? null);
    }

    public function test_validate_only_is_set_when_asked(): void
    {
        $device = DeviceToken::of(token: 't', platform: Platform::Android);

        $with = json_decode(
            FcmMessage::build($device, Notification::of(title: 'x'), 'app', validateOnly: true)->json,
            true,
        );
        $without = json_decode(
            FcmMessage::build($device, Notification::of(title: 'x'), 'app')->json,
            true,
        );

        self::assertTrue($with['validate_only'] ?? null);
        self::assertArrayNotHasKey('validate_only', $without);
    }
}
