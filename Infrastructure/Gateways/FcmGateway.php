<?php

declare(strict_types=1);

namespace Plugins\Push\Infrastructure\Gateways;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HttpClientPort;
use Plugins\Push\Domain\Contracts\PushTransport;
use Plugins\Push\Domain\DeliveryOutcome;
use Plugins\Push\Domain\DeliveryReport;
use Plugins\Push\Domain\DeviceToken;
use Plugins\Push\Domain\Exceptions\PushException;
use Plugins\Push\Domain\FcmErrorCode;
use Plugins\Push\Domain\FcmMessage;
use Plugins\Push\Domain\Notification;
use Plugins\Push\Domain\Provider;
use Plugins\Push\Infrastructure\Auth\GoogleAccessToken;
use Plugins\Push\Infrastructure\Config\PushConfig;

/**
 * Sends one notification through FCM HTTP v1.
 *
 * ONE MESSAGE, ONE DEVICE — AND THAT IS THE API, NOT A SHORTCUT
 * -------------------------------------------------------------
 * HTTP v1 has no multicast field. The legacy API took a
 * `registration_ids` array; v1 takes a single target, and fan-out to many
 * devices is many requests. Firebase's own batch helpers cap at "up to 500
 * FIDs and/or tokens per invocation" and are SDK-side loops, not one call.
 *
 * That shapes everything downstream: a notification to a user with four
 * devices is four requests, and a broadcast to ten thousand is ten thousand.
 * It is why the outbox exists rather than a loop inside a request.
 *
 * DRY RUN IS REAL HERE
 * --------------------
 * FCM's send body takes `validate_only` — "an optional flag for testing a
 * request without delivering the message". That is genuinely useful and has no
 * APNs equivalent: it exercises the credentials, the project id, the token and
 * the whole message shape, and delivers nothing. `push:status` uses it, which
 * is why that command can prove an FCM configuration works without picking a
 * victim to notify.
 */
final class FcmGateway implements PushTransport
{
    public function __construct(
        private readonly PushConfig $config,
        private readonly HttpClientPort $http,
        private readonly GoogleAccessToken $accessToken,
        private readonly ?\Closure $clock = null,
    ) {
    }

    public function send(
        DeviceToken $device,
        Notification $notification,
        bool $validateOnly = false,
    ): DeliveryReport {
        if (!$this->config->enabled) {
            return DeliveryReport::skipped(Provider::Fcm, 'PUSH_ENABLED is false.');
        }

        $validateOnly = $validateOnly || $this->config->dryRun;

        try {
            $this->config->assertFcmUsable();

            $message = FcmMessage::build(
                $device,
                $notification,
                $this->config->apnsTopic,
                $validateOnly,
                $this->now(),
            );

            $authorization = $this->accessToken->header();
        } catch (PushException $e) {
            return DeliveryReport::of(
                DeliveryOutcome::Rejected,
                Provider::Fcm,
                'refused-locally',
                $e->getMessage(),
            );
        }

        try {
            $response = $this->http->request('POST', $this->config->fcmSendUrl(), [
                'headers' => [
                    'authorization' => $authorization,
                    'content-type'  => 'application/json; UTF-8',
                ],
                'body'            => $message->json,
                'timeout'         => $this->config->timeout,
                'connect_timeout' => $this->config->connectTimeout,
            ]);
        } catch (\Throwable $e) {
            return DeliveryReport::unreachable(
                Provider::Fcm,
                'could not reach ' . $this->config->fcmEndpoint . ': ' . $e->getMessage(),
            );
        }

        $body = $response->json();
        $body = \is_array($body) ? $body : [];

        if ($response->ok()) {
            // A success body is {"name": "projects/{project}/messages/{id}"}.
            $name = \is_string($body['name'] ?? null) ? $body['name'] : '';

            if ($validateOnly) {
                return DeliveryReport::skipped(
                    Provider::Fcm,
                    'validate_only: FCM accepted the whole request — credentials, project, token and '
                    . 'message shape — and delivered nothing.',
                );
            }

            return DeliveryReport::delivered(Provider::Fcm, $name);
        }

        return $this->classify($response->status(), $body, $response->header('retry-after'));
    }

    /**
     * Turn an FCM error body into a report.
     *
     * The code is read from `error.details[].errorCode`, not from the HTTP
     * status: on this API a 404 means UNREGISTERED — "delete this token" — and
     * a client that read 404 as "wrong endpoint" would keep every dead token
     * forever.
     *
     * @param array<string, mixed> $body
     */
    private function classify(int $status, array $body, ?string $retryAfter): DeliveryReport
    {
        $code    = FcmErrorCode::fromResponse($body);
        $message = FcmErrorCode::messageFrom($body);

        if ($code === null) {
            $outcome = match (true) {
                $status === 404             => DeliveryOutcome::TokenInvalid,
                $status === 429, $status >= 500 => DeliveryOutcome::Retry,
                $status === 401, $status === 403 => DeliveryOutcome::Rejected,
                default                     => DeliveryOutcome::Rejected,
            };

            if ($status === 401) {
                // The access token may simply have gone stale between the cache
                // and the request. Drop it so the retry mints a new one.
                $this->accessToken->forget();
                $outcome = DeliveryOutcome::Retry;
            }

            return DeliveryReport::of(
                $outcome,
                Provider::Fcm,
                'http-' . $status,
                \sprintf(
                    'FCM answered HTTP %d with no recognisable error code%s. %s',
                    $status,
                    $message !== '' ? ': ' . $message : '',
                    $status === 401
                        ? 'A 401 usually means the access token expired mid-flight; it has been dropped '
                          . 'so the next attempt mints a fresh one.'
                        : '',
                ),
                $status,
                self::retryAfterSeconds($retryAfter),
            );
        }

        $outcome = $code->outcome();

        // INVALID_ARGUMENT covers six faults and only one is the token's.
        // Firebase's "Manage registration tokens" page names UNREGISTERED and
        // INVALID_ARGUMENT as the two invalid-registration codes, while
        // warning the latter "can also be returned in cases of issues in the
        // message payload" — so the MESSAGE decides.
        if ($code->indicatesInvalidToken($message)) {
            $outcome = DeliveryOutcome::TokenInvalid;
        }

        $detail = $code->explain();

        if ($message !== '') {
            $detail .= ' FCM said: "' . $message . '".';
        }

        return DeliveryReport::of(
            $outcome,
            Provider::Fcm,
            $code->value,
            $detail,
            $status,
            max($code->minimumBackoffSeconds(), self::retryAfterSeconds($retryAfter)),
        );
    }

    /**
     * `Retry-After` is either a delay in seconds or an HTTP date.
     *
     * Both forms are honoured because Firebase says to honour the header and
     * does not say which form it sends — and reading a date as an integer
     * yields 0, which turns "wait two minutes" into "retry immediately" and
     * earns a longer limit.
     */
    private static function retryAfterSeconds(?string $header): int
    {
        if ($header === null || trim($header) === '') {
            return 0;
        }

        $header = trim($header);

        if (ctype_digit($header)) {
            return (int) $header;
        }

        $at = strtotime($header);

        return $at === false ? 0 : max(0, $at - time());
    }

    private function now(): int
    {
        return $this->clock !== null ? (int) ($this->clock)() : time();
    }
}
