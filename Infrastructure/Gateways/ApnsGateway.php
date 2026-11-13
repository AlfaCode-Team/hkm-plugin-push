<?php

declare(strict_types=1);

namespace Plugins\Push\Infrastructure\Gateways;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\GatewayException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HttpClientPort;
use Plugins\Push\Domain\ApnsPayload;
use Plugins\Push\Domain\Contracts\PushTransport;
use Plugins\Push\Domain\ApnsReason;
use Plugins\Push\Domain\DeliveryOutcome;
use Plugins\Push\Domain\DeliveryReport;
use Plugins\Push\Domain\DeviceToken;
use Plugins\Push\Domain\Exceptions\PushException;
use Plugins\Push\Domain\Notification;
use Plugins\Push\Domain\Provider;
use Plugins\Push\Infrastructure\Auth\ApnsProviderToken;
use Plugins\Push\Infrastructure\Config\PushConfig;

/**
 * Sends one notification to APNs.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * HTTP/2 IS NOT OPTIONAL, SO IT IS NOT LEFT TO A DEFAULT
 * ────────────────────────────────────────────────────────────────────────────
 * Apple: "Use HTTP/2 and TLS 1.2 or later to establish a connection between
 * your provider server and one of the following servers."
 *
 * libcurl happens to default to HTTP/2 over TLS since 7.62, so this works by
 * accident on most builds — and measured here, `api.push.apple.com` does
 * negotiate HTTP/2 with no option set. But "by accident" is a bad foundation
 * for a hard requirement: a cURL built without nghttp2 silently falls back to
 * HTTP/1.1, which APNs refuses, and the failure would look like a network
 * problem rather than a missing library. So the request ASKS for HTTP/2
 * explicitly, through the `http_version` option, and
 * {@see self::assertHttp2Available()} refuses to start if this build cannot do
 * it at all.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * THE ENVIRONMENT COMES FROM THE DEVICE, NOT THE CONFIGURATION
 * ────────────────────────────────────────────────────────────────────────────
 * One deployment routinely holds both sandbox and production tokens, from the
 * same app, at the same time — TestFlight builds carry production tokens while
 * Xcode builds carry sandbox ones. Sending to the wrong host is
 * `400 BadDeviceToken`, which is indistinguishable from a corrupt token, so a
 * sender that used one configured host would prune half its real devices.
 */
final class ApnsGateway implements PushTransport
{
    public function __construct(
        private readonly PushConfig $config,
        private readonly HttpClientPort $http,
        private readonly ApnsProviderToken $providerToken,
        /** Injected so a test controls `apns-expiration` and `apns-id`. */
        private readonly ?\Closure $clock = null,
        private readonly ?\Closure $uuid = null,
    ) {
    }

    /**
     * @throws GatewayException on a transport failure this plugin cannot classify
     */
    public function send(DeviceToken $device, Notification $notification): DeliveryReport
    {
        if (!$this->config->enabled) {
            return DeliveryReport::skipped(Provider::Apns, 'PUSH_ENABLED is false.');
        }

        try {
            $this->config->assertApnsUsable();
            $this->assertHttp2Available();
            $payload = ApnsPayload::build($notification);
        } catch (PushException $e) {
            // A configuration or payload fault, not a delivery failure. It
            // cannot be retried into working, so it is Rejected and the
            // message says what to change.
            return DeliveryReport::of(
                DeliveryOutcome::Rejected,
                Provider::Apns,
                'refused-locally',
                $e->getMessage(),
            );
        }

        $now   = $this->now();
        $id    = $this->messageId();
        $topic = $notification->topic !== ''
            ? $notification->topic
            : $this->config->apnsTopic . $notification->type->topicSuffix();

        $headers = [
            'authorization'  => $this->providerToken->header(),
            'apns-push-type' => $notification->type->value,
            'apns-priority'  => (string) $notification->priority->value,
            'apns-topic'     => $topic,
            'apns-id'        => $id,
            // Content-Type is NOT set. Apple does not require it, and the
            // adapter would otherwise have to be told not to re-encode a body
            // that is already the exact bytes the size check was run against.
        ];

        $expiration = $notification->expirationAt($now);

        if ($expiration !== null) {
            $headers['apns-expiration'] = (string) $expiration;
        }

        if ($notification->collapseId !== '') {
            $headers['apns-collapse-id'] = $notification->collapseId;
        }

        if ($this->config->dryRun) {
            return DeliveryReport::skipped(
                Provider::Apns,
                'PUSH_DRY_RUN is on. APNs has no validate-only mode — unlike FCM — so nothing was sent '
                . 'at all. The payload was built and size-checked (' . $payload->bytes . ' bytes), which '
                . 'is everything that can be verified without delivering to a real device.',
            );
        }

        $url = $this->config->apnsBaseUrl($device->environment) . '/3/device/' . $device->token;

        try {
            $response = $this->http->request('POST', $url, [
                'headers'         => $headers,
                'body'            => $payload->json,
                'timeout'         => $this->config->timeout,
                'connect_timeout' => $this->config->connectTimeout,
                'http_version'    => '2',
            ]);
        } catch (\Throwable $e) {
            return DeliveryReport::unreachable(
                Provider::Apns,
                'could not reach ' . $device->environment->host() . ': ' . $e->getMessage(),
            );
        }

        if ($response->status() === 200) {
            return DeliveryReport::delivered(
                Provider::Apns,
                $response->header('apns-id') ?? $id,
            );
        }

        return $this->classify($response->status(), $response->body(), $response->header('apns-id') ?? $id, $device);
    }

    /**
     * Turn a non-200 into a report.
     *
     * The reason lives in the JSON body, and the body may be ABSENT — a 503
     * from an intermediary never reaches APNs' own error handler. So the status
     * is the fallback, and an unknown reason string is not treated as fatal:
     * Apple adds reasons, and a build that treated an unrecognised one as
     * "reject permanently" would stop delivering on the day they do.
     */
    private function classify(int $status, string $body, string $id, DeviceToken $device): DeliveryReport
    {
        $decoded = json_decode($body, true);
        $decoded = \is_array($decoded) ? $decoded : [];
        $reason  = ApnsReason::parse(\is_string($decoded['reason'] ?? null) ? $decoded['reason'] : null);

        if ($reason === null) {
            // No reason, or one this build has never heard of.
            $outcome = match (true) {
                $status === 410             => DeliveryOutcome::TokenInvalid,
                $status === 429, $status >= 500 => DeliveryOutcome::Retry,
                default                     => DeliveryOutcome::Rejected,
            };

            return DeliveryReport::of(
                $outcome,
                Provider::Apns,
                \is_string($decoded['reason'] ?? null) ? $decoded['reason'] : 'unknown',
                \sprintf(
                    'APNs answered HTTP %d%s. %s',
                    $status,
                    \is_string($decoded['reason'] ?? null)
                        ? ' with reason "' . $decoded['reason'] . '", which this build does not recognise'
                        : ' with no reason in the body',
                    $outcome === DeliveryOutcome::Retry
                        ? 'Treated as transient.'
                        : 'Classified from the status alone.',
                ),
                $status,
                0,
                $id,
            );
        }

        $outcome = $reason->outcome();
        $detail  = $reason->explain();

        // The 410 timestamp rule. Apple gives two facts and the conclusion
        // follows from both: the token "is inactive for the specified topic …
        // unless your application retrieves the same device token", and the
        // timestamp is "the time … at which APNs confirmed the token was no
        // longer valid for the topic". So a registration made AFTER that
        // moment is newer than Apple's knowledge, and the token is live again.
        // This comparison is our reading of those two sentences rather than a
        // rule Apple states outright, which is why the caller is told the
        // timestamp instead of simply being handed a verdict.
        $invalidatedAt = $decoded['timestamp'] ?? null;

        if ($status === 410 && \is_numeric($invalidatedAt)) {
            $detail .= \sprintf(
                ' APNs confirmed the token was dead at %s (timestamp %d, milliseconds). If this device '
                . 'registered again after that, the registration is newer than Apple\'s knowledge and the '
                . 'token is live — the caller compares the two before deleting.',
                gmdate('c', (int) ((int) $invalidatedAt / 1000)),
                (int) $invalidatedAt,
            );
        }

        if ($reason->mayBeEnvironmentMismatch()) {
            $detail .= \sprintf(
                ' This device is registered as %s and was sent to %s — if that pairing is wrong, the '
                . 'token is fine and the environment is not.',
                $device->environment->value,
                $device->environment->host(),
            );
        }

        if ($reason === ApnsReason::ExpiredProviderToken || $reason === ApnsReason::UnrelatedKeyIdInToken) {
            // Our cached token and Apple disagree, and Apple is right. Drop it
            // so the retry mints a fresh one instead of presenting the same
            // dead token until its TTL runs out.
            $this->providerToken->forget();
        }

        return DeliveryReport::of(
            $outcome,
            Provider::Apns,
            $reason->value,
            $detail,
            $status,
            $status === 429 ? 60 : 0,
            $id,
        );
    }

    /**
     * Refuse to start if this build cannot speak HTTP/2 at all.
     *
     * @throws PushException
     */
    private function assertHttp2Available(): void
    {
        if (!\function_exists('curl_version')) {
            // Not necessarily fatal — the HttpClientPort adapter may not be
            // cURL — so this only checks when it can.
            return;
        }

        $version = curl_version();

        if (\is_array($version) && \defined('CURL_VERSION_HTTP2')
            && (($version['features'] ?? 0) & \CURL_VERSION_HTTP2) === 0) {
            throw PushException::misconfigured(
                'the cURL extension',
                'was built without HTTP/2 support, and Apple requires it: "Use HTTP/2 and TLS 1.2 or '
                . 'later to establish a connection". Without nghttp2 every request falls back to '
                . 'HTTP/1.1, which APNs refuses — and the failure looks like a network problem rather '
                . 'than a missing library, which is why it is checked here. Rebuild PHP\'s cURL with '
                . 'nghttp2, or deliver to iOS through FCM instead.',
            );
        }
    }

    private function now(): int
    {
        return $this->clock !== null ? (int) ($this->clock)() : time();
    }

    /**
     * A canonical UUID for `apns-id`.
     *
     * Supplied rather than left to APNs so the id is known BEFORE the request
     * goes out: a timeout gives no response headers, and without an id there
     * is nothing to correlate a log line with if the notification turns out to
     * have been delivered anyway.
     */
    private function messageId(): string
    {
        if ($this->uuid !== null) {
            return (string) ($this->uuid)();
        }

        $bytes = random_bytes(16);
        // RFC 4122 version 4.
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
