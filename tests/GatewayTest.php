<?php

declare(strict_types=1);

namespace Plugins\Push\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Push\Domain\DeliveryOutcome;
use Plugins\Push\Domain\DeviceToken;
use Plugins\Push\Domain\Notification;
use Plugins\Push\Domain\Platform;
use Plugins\Push\Domain\Provider;
use Plugins\Push\Domain\PushEnvironment;
use Plugins\Push\Infrastructure\Auth\ApnsProviderToken;
use Plugins\Push\Infrastructure\Auth\GoogleAccessToken;
use Plugins\Push\Infrastructure\Config\PushConfig;
use Plugins\Push\Infrastructure\Gateways\ApnsGateway;
use Plugins\Push\Infrastructure\Gateways\FcmGateway;
use Plugins\Push\Tests\Support\FakeHttpClient;

/**
 * What the gateways actually put on the wire, and how they read the answer.
 *
 * The REQUEST assertions matter as much as the response ones. APNs' headers
 * are the part that fails silently when wrong — a missing `apns-topic` is a
 * refusal, and a mismatched `apns-push-type` "may … drop it altogether" — so a
 * test that only checked classification would never notice them going missing.
 */
#[CoversClass(ApnsGateway::class)]
#[CoversClass(FcmGateway::class)]
final class GatewayTest extends TestCase
{
    private const APNS_TOKEN = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    private static string $ecKey = '';

    public static function setUpBeforeClass(): void
    {
        $ec = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => \OPENSSL_KEYTYPE_EC]);
        self::assertNotFalse($ec, 'this build of openssl cannot generate a P-256 key');
        openssl_pkey_export($ec, $private);
        self::$ecKey = (string) $private;
    }

    /** @param array<string, string> $overrides */
    private static function apnsConfig(array $overrides = []): PushConfig
    {
        foreach ([
            'PUSH_ENABLED'          => 'true',
            'PUSH_DRY_RUN'          => 'false',
            'PUSH_APNS_ENABLED'     => 'true',
            'PUSH_APNS_KEY_ID'      => 'ABC123DEFG',
            'PUSH_APNS_TEAM_ID'     => 'DEF123GHIJ',
            'PUSH_APNS_KEY'         => self::$ecKey,
            'PUSH_APNS_TOPIC'       => 'com.example.app',
            'PUSH_APNS_ENVIRONMENT' => 'production',
            'PUSH_APNS_ENDPOINT'    => '',
            'PUSH_FCM_ENABLED'      => 'false',
            'PUSH_FCM_PROJECT_ID'   => '',
            'PUSH_FCM_CREDENTIALS'  => '',
            ...$overrides,
        ] as $key => $value) {
            $_ENV[$key] = $value;
        }

        return PushConfig::fromEnv();
    }

    /** @param array<string, string> $overrides */
    private static function fcmConfig(array $overrides = []): PushConfig
    {
        $rsa = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        \PHPUnit\Framework\Assert::assertNotFalse($rsa, 'openssl cannot generate an RSA key');
        openssl_pkey_export($rsa, $rsaPrivate);

        $account = json_encode([
            'type'           => 'service_account',
            'project_id'     => 'demo-project',
            'client_email'   => 'svc@demo-project.iam.gserviceaccount.com',
            'private_key'    => (string) $rsaPrivate,
            'private_key_id' => 'pk-1',
        ]);

        foreach ([
            'PUSH_ENABLED'         => 'true',
            'PUSH_DRY_RUN'         => 'false',
            'PUSH_FCM_ENABLED'     => 'true',
            'PUSH_FCM_PROJECT_ID'  => 'demo-project',
            'PUSH_FCM_CREDENTIALS' => (string) $account,
            'PUSH_APNS_ENABLED'    => 'false',
            'PUSH_APNS_TOPIC'      => 'com.example.app',
            ...$overrides,
        ] as $key => $value) {
            $_ENV[$key] = $value;
        }

        return PushConfig::fromEnv();
    }

    private static function apnsDevice(PushEnvironment $env = PushEnvironment::Production): DeviceToken
    {
        return DeviceToken::of(
            token: self::APNS_TOKEN,
            platform: Platform::Ios,
            environment: $env,
            userId: 'user-1',
        );
    }

    private function apnsGateway(FakeHttpClient $http, ?PushConfig $config = null): ApnsGateway
    {
        $config ??= self::apnsConfig();

        return new ApnsGateway(
            $config,
            $http,
            new ApnsProviderToken($config, null, static fn (): int => 1_700_000_000),
            clock: static fn (): int => 1_700_000_000,
            uuid: static fn (): string => '123e4567-e89b-12d3-a456-4266554400a0',
        );
    }

    // ── APNs: the request ───────────────────────────────────────────────────

    public function test_the_url_is_the_device_path_on_the_right_host(): void
    {
        $http = (new FakeHttpClient())->queue(200);
        $this->apnsGateway($http)->send(self::apnsDevice(), Notification::of(title: 'Hi'));

        $sent = $http->last();
        self::assertNotNull($sent);
        self::assertSame('https://api.push.apple.com:443/3/device/' . self::APNS_TOKEN, $sent['url']);
        self::assertSame('POST', $sent['method']);
    }

    public function test_a_sandbox_device_goes_to_the_sandbox_host(): void
    {
        // The environment comes from the DEVICE, not the configuration: one
        // deployment holds both, and sending to the wrong host is
        // BadDeviceToken — which reads as a dead token and prunes a live one.
        $http = (new FakeHttpClient())->queue(200);
        $this->apnsGateway($http)->send(
            self::apnsDevice(PushEnvironment::Sandbox),
            Notification::of(title: 'Hi'),
        );

        $sent = $http->last();
        self::assertNotNull($sent);
        self::assertStringContainsString('api.sandbox.push.apple.com', $sent['url']);
    }

    public function test_http_2_is_requested_explicitly_rather_than_left_to_a_default(): void
    {
        $http = (new FakeHttpClient())->queue(200);
        $this->apnsGateway($http)->send(self::apnsDevice(), Notification::of(title: 'Hi'));

        self::assertSame(
            '2',
            $http->last()['options']['http_version'] ?? null,
            'Apple requires HTTP/2. libcurl happens to default to it over TLS, but a build without '
            . 'nghttp2 falls back to 1.1 — which APNs refuses, and which looks like a network fault',
        );
    }

    public function test_every_required_apns_header_is_present(): void
    {
        $http = (new FakeHttpClient())->queue(200);
        $this->apnsGateway($http)->send(
            self::apnsDevice(),
            Notification::of(title: 'Hi', body: 'There', collapseId: 'order-7', ttlSeconds: 300),
        );

        $headers = $http->lastHeaders();

        self::assertStringStartsWith('bearer ', $headers['authorization'], 'Apple\'s own spelling');
        self::assertSame('alert', $headers['apns-push-type']);
        self::assertSame('10', $headers['apns-priority']);
        self::assertSame('com.example.app', $headers['apns-topic'], 'Apple marks this Required');
        self::assertSame('123e4567-e89b-12d3-a456-4266554400a0', $headers['apns-id']);
        self::assertSame('order-7', $headers['apns-collapse-id']);
        self::assertSame(
            (string) (1_700_000_000 + 300),
            $headers['apns-expiration'],
            'an absolute UNIX time, resolved at send and not at construction',
        );
    }

    public function test_apns_expiration_is_omitted_when_no_ttl_was_given(): void
    {
        // Apple: omitted means "APNs stores the push according to APNs storage
        // policy", while 0 means one attempt and no storage. They are different
        // instructions, so a default would be a decision the caller did not make.
        $http = (new FakeHttpClient())->queue(200);
        $this->apnsGateway($http)->send(self::apnsDevice(), Notification::of(title: 'Hi'));

        self::assertArrayNotHasKey('apns-expiration', $http->lastHeaders());
    }

    public function test_the_provider_token_is_minted_once_for_many_sends(): void
    {
        $config = self::apnsConfig();
        $token  = new ApnsProviderToken($config, null, static fn (): int => 1_700_000_000);

        self::assertSame(
            $token->token(),
            $token->token(),
            'Apple: "Update the authentication token no more than once every 20 minutes." A fresh '
            . 'token per send earns 429 TooManyProviderTokenUpdates',
        );
    }

    // ── APNs: the response ──────────────────────────────────────────────────

    public function test_a_200_is_delivered_and_keeps_apples_message_id(): void
    {
        $http   = (new FakeHttpClient())->queue(200, '', ['apns-id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee']);
        $report = $this->apnsGateway($http)->send(self::apnsDevice(), Notification::of(title: 'Hi'));

        self::assertTrue($report->isDelivered());
        self::assertSame('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $report->providerMessageId);
    }

    public function test_410_unregistered_prunes_and_reports_the_timestamp(): void
    {
        $http = (new FakeHttpClient())->queue(
            410,
            '{"reason":"Unregistered","timestamp":1700000000000}',
        );

        $report = $this->apnsGateway($http)->send(self::apnsDevice(), Notification::of(title: 'Hi'));

        self::assertTrue($report->outcome->prunesToken());
        self::assertSame('Unregistered', $report->reason);
        self::assertStringContainsString(
            'registered again after that',
            $report->detail,
            'the caller has to be able to tell a re-registration from a dead token',
        );
    }

    public function test_an_unknown_reason_is_classified_from_the_status_not_rejected(): void
    {
        // Apple adds reasons. A build that treated an unrecognised one as a
        // permanent rejection would stop delivering on the day they do.
        $http   = (new FakeHttpClient())->queue(503, '{"reason":"SomethingBrandNew"}');
        $report = $this->apnsGateway($http)->send(self::apnsDevice(), Notification::of(title: 'Hi'));

        self::assertSame(DeliveryOutcome::Retry, $report->outcome);
        self::assertStringContainsString('does not recognise', $report->detail);
    }

    public function test_a_body_less_error_still_classifies(): void
    {
        $http   = (new FakeHttpClient())->queue(503, '');
        $report = $this->apnsGateway($http)->send(self::apnsDevice(), Notification::of(title: 'Hi'));

        self::assertSame(DeliveryOutcome::Retry, $report->outcome);
        self::assertStringContainsString('no reason in the body', $report->detail);
    }

    public function test_a_transport_failure_is_a_retry_and_never_prunes(): void
    {
        $http   = (new FakeHttpClient())->queueThrow(new \RuntimeException('connection refused'));
        $report = $this->apnsGateway($http)->send(self::apnsDevice(), Notification::of(title: 'Hi'));

        self::assertSame(DeliveryOutcome::Retry, $report->outcome);
        self::assertFalse(
            $report->outcome->prunesToken(),
            'a failed connection says nothing about the token, and must never discard a valid one',
        );
    }

    public function test_an_expired_provider_token_drops_the_cached_one(): void
    {
        $config = self::apnsConfig();
        $token  = new ApnsProviderToken($config, null, static fn (): int => 1_700_000_000);
        $first  = $token->token();

        $gateway = new ApnsGateway(
            $config,
            (new FakeHttpClient())->queue(403, '{"reason":"ExpiredProviderToken"}'),
            $token,
            clock: static fn (): int => 1_700_000_000,
        );

        $report = $gateway->send(self::apnsDevice(), Notification::of(title: 'Hi'));

        self::assertSame(DeliveryOutcome::Retry, $report->outcome);
        // Forgotten, so the next attempt mints a fresh one rather than
        // presenting the same dead token until its TTL runs out.
        self::assertNotSame($first, $token->token());
    }

    public function test_dry_run_sends_nothing_to_apns(): void
    {
        $http   = new FakeHttpClient();
        $report = $this->apnsGateway($http, self::apnsConfig(['PUSH_DRY_RUN' => 'true']))
            ->send(self::apnsDevice(), Notification::of(title: 'Hi'));

        self::assertSame([], $http->sent, 'APNs has no validate-only mode, so nothing may be sent');
        self::assertStringContainsString('no validate-only mode', $report->detail);
    }

    public function test_push_disabled_is_a_no_op(): void
    {
        $http   = new FakeHttpClient();
        $report = $this->apnsGateway($http, self::apnsConfig(['PUSH_ENABLED' => 'false']))
            ->send(self::apnsDevice(), Notification::of(title: 'Hi'));

        self::assertSame([], $http->sent);
        self::assertSame('skipped', $report->reason);
    }

    public function test_a_missing_topic_is_refused_locally_rather_than_by_apple(): void
    {
        $report = $this->apnsGateway(new FakeHttpClient(), self::apnsConfig(['PUSH_APNS_TOPIC' => '']))
            ->send(self::apnsDevice(), Notification::of(title: 'Hi'));

        self::assertSame(DeliveryOutcome::Rejected, $report->outcome);
        self::assertStringContainsString('PUSH_APNS_TOPIC', $report->detail);
    }

    public function test_a_key_id_of_the_wrong_length_names_the_setting(): void
    {
        $report = $this->apnsGateway(new FakeHttpClient(), self::apnsConfig(['PUSH_APNS_KEY_ID' => 'SHORT']))
            ->send(self::apnsDevice(), Notification::of(title: 'Hi'));

        self::assertSame(DeliveryOutcome::Rejected, $report->outcome);
        self::assertStringContainsString('10-character Key ID', $report->detail);
    }

    // ── FCM ─────────────────────────────────────────────────────────────────

    private function fcmGateway(FakeHttpClient $http, ?PushConfig $config = null): FcmGateway
    {
        $config ??= self::fcmConfig();

        return new FcmGateway(
            $config,
            $http,
            new GoogleAccessToken($config, $http, null, static fn (): int => 1_700_000_000),
            clock: static fn (): int => 1_700_000_000,
        );
    }

    private static function fcmDevice(): DeviceToken
    {
        return DeviceToken::of(token: 'fcm-registration-token', platform: Platform::Android, userId: 'user-1');
    }

    public function test_fcm_exchanges_the_assertion_then_sends(): void
    {
        $http = (new FakeHttpClient())
            ->queue(200, '{"access_token":"ya29.token","expires_in":3600}')
            ->queue(200, '{"name":"projects/demo-project/messages/0:123"}');

        $report = $this->fcmGateway($http)->send(self::fcmDevice(), Notification::of(title: 'Hi'));

        self::assertCount(2, $http->sent, 'two hops: the token endpoint, then the send endpoint');
        self::assertSame('https://oauth2.googleapis.com/token', $http->sent[0]['url']);
        self::assertSame(
            'urn:ietf:params:oauth:grant-type:jwt-bearer',
            $http->sent[0]['options']['form']['grant_type'] ?? null,
        );
        self::assertSame(
            'https://fcm.googleapis.com/v1/projects/demo-project/messages:send',
            $http->sent[1]['url'],
        );
        self::assertTrue($report->isDelivered());
        self::assertSame('projects/demo-project/messages/0:123', $report->providerMessageId);
    }

    public function test_the_send_carries_a_bearer_token(): void
    {
        $http = (new FakeHttpClient())
            ->queue(200, '{"access_token":"ya29.token","expires_in":3600}')
            ->queue(200, '{"name":"projects/x/messages/1"}');

        $this->fcmGateway($http)->send(self::fcmDevice(), Notification::of(title: 'Hi'));

        self::assertSame('Bearer ya29.token', $http->lastHeaders()['authorization'] ?? null);
    }

    public function test_UNREGISTERED_in_details_prunes_even_though_the_status_is_404(): void
    {
        $http = (new FakeHttpClient())
            ->queue(200, '{"access_token":"t","expires_in":3600}')
            ->queue(404, (string) json_encode([
                'error' => [
                    'code'    => 404,
                    'status'  => 'NOT_FOUND',
                    'message' => 'Requested entity was not found.',
                    'details' => [[
                        '@type'     => 'type.googleapis.com/google.firebase.fcm.v1.FcmError',
                        'errorCode' => 'UNREGISTERED',
                    ]],
                ],
            ]));

        $report = $this->fcmGateway($http)->send(self::fcmDevice(), Notification::of(title: 'Hi'));

        self::assertTrue($report->outcome->prunesToken());
        self::assertSame('UNREGISTERED', $report->reason);
    }

    public function test_an_INVALID_ARGUMENT_about_the_payload_does_NOT_prune(): void
    {
        $http = (new FakeHttpClient())
            ->queue(200, '{"access_token":"t","expires_in":3600}')
            ->queue(400, (string) json_encode([
                'error' => [
                    'status'  => 'INVALID_ARGUMENT',
                    'message' => "Invalid value at 'message.data[0].value' (TYPE_STRING), 12",
                    'details' => [[
                        '@type'     => 'type.googleapis.com/google.firebase.fcm.v1.FcmError',
                        'errorCode' => 'INVALID_ARGUMENT',
                    ]],
                ],
            ]));

        $report = $this->fcmGateway($http)->send(self::fcmDevice(), Notification::of(title: 'Hi'));

        self::assertFalse(
            $report->outcome->prunesToken(),
            'pruning here would destroy a live registration because a data key was mistyped',
        );
        self::assertSame(DeliveryOutcome::Rejected, $report->outcome);
    }

    public function test_an_INVALID_ARGUMENT_about_the_TOKEN_does_prune(): void
    {
        $http = (new FakeHttpClient())
            ->queue(200, '{"access_token":"t","expires_in":3600}')
            ->queue(400, (string) json_encode([
                'error' => [
                    'status'  => 'INVALID_ARGUMENT',
                    'message' => 'The registration token is not a valid FCM registration token',
                    'details' => [[
                        '@type'     => 'type.googleapis.com/google.firebase.fcm.v1.FcmError',
                        'errorCode' => 'INVALID_ARGUMENT',
                    ]],
                ],
            ]));

        $report = $this->fcmGateway($http)->send(self::fcmDevice(), Notification::of(title: 'Hi'));

        self::assertTrue($report->outcome->prunesToken(), 'Firebase\'s own example message for a bad token');
    }

    public function test_a_quota_error_carries_firebases_minimum_backoff(): void
    {
        $http = (new FakeHttpClient())
            ->queue(200, '{"access_token":"t","expires_in":3600}')
            ->queue(429, (string) json_encode([
                'error' => [
                    'status'  => 'RESOURCE_EXHAUSTED',
                    'details' => [[
                        '@type'     => 'type.googleapis.com/google.firebase.fcm.v1.FcmError',
                        'errorCode' => 'QUOTA_EXCEEDED',
                    ]],
                ],
            ]));

        $report = $this->fcmGateway($http)->send(self::fcmDevice(), Notification::of(title: 'Hi'));

        self::assertSame(DeliveryOutcome::Retry, $report->outcome);
        self::assertGreaterThanOrEqual(60, $report->retryAfter);
    }

    public function test_a_retry_after_HTTP_DATE_is_honoured_not_read_as_zero(): void
    {
        $http = (new FakeHttpClient())
            ->queue(200, '{"access_token":"t","expires_in":3600}')
            ->queue(503, '{"error":{"status":"UNAVAILABLE"}}', [
                'retry-after' => gmdate('D, d M Y H:i:s \G\M\T', time() + 120),
            ]);

        $report = $this->fcmGateway($http)->send(self::fcmDevice(), Notification::of(title: 'Hi'));

        self::assertGreaterThan(
            60,
            $report->retryAfter,
            'reading a date as an integer yields 0, which turns "wait two minutes" into "retry now"',
        );
    }

    public function test_dry_run_uses_fcms_own_validate_only(): void
    {
        $http = (new FakeHttpClient())
            ->queue(200, '{"access_token":"t","expires_in":3600}')
            ->queue(200, '{}');

        $report = $this->fcmGateway($http, self::fcmConfig(['PUSH_DRY_RUN' => 'true']))
            ->send(self::fcmDevice(), Notification::of(title: 'Hi'));

        self::assertTrue($http->lastJson()['validate_only'] ?? false);
        self::assertStringContainsString('delivered nothing', $report->detail);
    }

    public function test_a_bad_service_account_is_reported_from_the_token_exchange(): void
    {
        $http = (new FakeHttpClient())->queue(
            400,
            '{"error":"invalid_grant","error_description":"Invalid JWT Signature."}',
        );

        $report = $this->fcmGateway($http)->send(self::fcmDevice(), Notification::of(title: 'Hi'));

        self::assertSame(DeliveryOutcome::Rejected, $report->outcome);
        self::assertStringContainsString('clock', $report->detail, 'the cheaper of the two causes to rule out');
    }

    public function test_a_401_on_send_drops_the_cached_access_token(): void
    {
        $http = (new FakeHttpClient())
            ->queue(200, '{"access_token":"stale","expires_in":3600}')
            ->queue(401, '{"error":{"status":"UNAUTHENTICATED"}}');

        $report = $this->fcmGateway($http)->send(self::fcmDevice(), Notification::of(title: 'Hi'));

        self::assertSame(DeliveryOutcome::Retry, $report->outcome);
        self::assertStringContainsString('expired mid-flight', $report->detail);
    }

    public function test_an_ios_device_registered_through_firebase_routes_to_fcm(): void
    {
        $device = DeviceToken::of(
            token: 'fcm-issued-for-an-iphone',
            platform: Platform::Ios,
            provider: Provider::Fcm,
        );

        self::assertSame(
            Provider::Fcm,
            $device->provider,
            'the platform says what the device IS; the provider says who issued the string, and '
            . 'routing on the platform would send this to Apple, which has never heard of it',
        );
    }
}
