<?php

declare(strict_types=1);

namespace Plugins\Push\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Push\Domain\ApnsReason;
use Plugins\Push\Domain\DeliveryOutcome;
use Plugins\Push\Domain\FcmErrorCode;

/**
 * The two provider error tables, transcribed from the vendors' own references.
 *
 * These are the decisions that cost real data when wrong: prune a live token
 * and the user stops receiving anything until the app runs again; keep a dead
 * one and the outbox retries it forever.
 */
#[CoversClass(ApnsReason::class)]
#[CoversClass(FcmErrorCode::class)]
final class ProviderErrorTablesTest extends TestCase
{
    // ── APNs ────────────────────────────────────────────────────────────────

    public function test_410_has_TWO_reasons_and_both_prune(): void
    {
        // The one that gets missed. Code that only knows `Unregistered` keeps
        // a dead token forever, because the status matched and the reason did
        // not.
        self::assertTrue(ApnsReason::Unregistered->outcome()->prunesToken());
        self::assertTrue(
            ApnsReason::ExpiredToken->outcome()->prunesToken(),
            'Apple lists ExpiredToken under 410 alongside Unregistered',
        );
    }

    public function test_a_token_for_the_wrong_topic_is_dead_for_this_sender(): void
    {
        self::assertTrue(ApnsReason::DeviceTokenNotForTopic->outcome()->prunesToken());
    }

    public function test_UnrelatedKeyIdInToken_is_a_RETRY_not_a_credential_failure(): void
    {
        // Apple: "To use this token, open a new connection." That is a
        // connection problem. Classifying it as bad credentials sends an
        // operator to rotate a key that was never wrong.
        self::assertSame(DeliveryOutcome::Retry, ApnsReason::UnrelatedKeyIdInToken->outcome());
        self::assertTrue(ApnsReason::UnrelatedKeyIdInToken->isCredentialProblem());
    }

    public function test_an_expired_provider_token_is_our_own_fault_and_self_healing(): void
    {
        self::assertSame(DeliveryOutcome::Retry, ApnsReason::ExpiredProviderToken->outcome());
    }

    public function test_an_invalid_provider_token_needs_a_human(): void
    {
        self::assertSame(DeliveryOutcome::Rejected, ApnsReason::InvalidProviderToken->outcome());
        self::assertStringContainsString('belong together', ApnsReason::InvalidProviderToken->explain());
    }

    public function test_bad_device_token_prunes_but_flags_the_environment(): void
    {
        // Apple's own wording ties the two together: "Verify that the request
        // contains a valid token and that the token matches the environment."
        self::assertTrue(ApnsReason::BadDeviceToken->outcome()->prunesToken());
        self::assertTrue(ApnsReason::BadDeviceToken->mayBeEnvironmentMismatch());
        self::assertStringContainsString('matches the environment', ApnsReason::BadDeviceToken->explain());
    }

    public function test_rate_limits_and_server_faults_retry(): void
    {
        foreach ([
            ApnsReason::TooManyRequests,
            ApnsReason::TooManyProviderTokenUpdates,
            ApnsReason::InternalServerError,
            ApnsReason::ServiceUnavailable,
            ApnsReason::Shutdown,
            ApnsReason::IdleTimeout,
        ] as $reason) {
            self::assertSame(DeliveryOutcome::Retry, $reason->outcome(), $reason->value);
        }
    }

    public function test_request_faults_are_rejected_rather_than_retried(): void
    {
        foreach ([
            ApnsReason::BadTopic,
            ApnsReason::TopicDisallowed,
            ApnsReason::MissingTopic,
            ApnsReason::PayloadTooLarge,
            ApnsReason::BadCollapseId,
            ApnsReason::InvalidPushType,
        ] as $reason) {
            self::assertSame(DeliveryOutcome::Rejected, $reason->outcome(), $reason->value);
        }
    }

    public function test_the_20_minute_rule_is_explained_where_an_operator_will_read_it(): void
    {
        self::assertStringContainsString(
            'once every 20 minutes',
            ApnsReason::TooManyProviderTokenUpdates->explain(),
        );
        self::assertStringContainsString(
            'CachePort',
            ApnsReason::TooManyProviderTokenUpdates->explain(),
            'the actual fix under PHP-FPM is a shared cache, so the message has to say so',
        );
    }

    public function test_an_unknown_reason_string_is_null_rather_than_a_guess(): void
    {
        self::assertNull(ApnsReason::parse('SomethingAppleAddedLastWeek'));
        self::assertNull(ApnsReason::parse(null));
        self::assertNull(ApnsReason::parse('  '));
        self::assertSame(ApnsReason::Unregistered, ApnsReason::parse(' Unregistered '));
    }

    // ── FCM ─────────────────────────────────────────────────────────────────

    public function test_only_UNREGISTERED_prunes_outright(): void
    {
        self::assertTrue(FcmErrorCode::Unregistered->outcome()->prunesToken());
        self::assertTrue(FcmErrorCode::Unregistered->indicatesInvalidToken());
    }

    public function test_SENDER_ID_MISMATCH_does_NOT_prune(): void
    {
        // The token is perfectly good; this is the wrong server for it.
        // Deleting it would destroy a live registration over a configuration
        // mistake.
        self::assertSame(DeliveryOutcome::Rejected, FcmErrorCode::SenderIdMismatch->outcome());
        self::assertFalse(FcmErrorCode::SenderIdMismatch->indicatesInvalidToken('anything'));
        self::assertTrue(FcmErrorCode::SenderIdMismatch->isConfigurationProblem());
    }

    public function test_INVALID_ARGUMENT_prunes_only_when_the_message_names_the_token(): void
    {
        $code = FcmErrorCode::InvalidArgument;

        self::assertTrue(
            $code->indicatesInvalidToken('The registration token is not a valid FCM registration token'),
            'Firebase\'s own example message for a bad token',
        );
        self::assertFalse(
            $code->indicatesInvalidToken("Invalid value at 'message.data[0].value' (TYPE_STRING), 12"),
            'Firebase\'s own example for a payload fault — pruning here would lose a live device '
            . 'because a data key was mistyped',
        );
        self::assertFalse($code->indicatesInvalidToken(''));
    }

    public function test_the_error_code_is_read_from_details_not_from_status(): void
    {
        // Firebase's example shape, verbatim in structure: the FCM-specific
        // code lives at error.details[].errorCode under the FcmError @type.
        $body = [
            'error' => [
                'code'    => 404,
                'message' => 'Requested entity was not found.',
                'status'  => 'NOT_FOUND',
                'details' => [
                    [
                        '@type'     => 'type.googleapis.com/google.firebase.fcm.v1.FcmError',
                        'errorCode' => 'UNREGISTERED',
                    ],
                ],
            ],
        ];

        self::assertSame(
            FcmErrorCode::Unregistered,
            FcmErrorCode::fromResponse($body),
            'error.status is NOT_FOUND here, which Firebase does not document as meaning UNREGISTERED '
            . '— details[] is the only reliable source, and this is the one code pruning depends on',
        );
    }

    public function test_a_non_fcm_detail_type_is_skipped(): void
    {
        $body = [
            'error' => [
                'status'  => 'INVALID_ARGUMENT',
                'details' => [
                    ['@type' => 'type.googleapis.com/google.rpc.BadRequest', 'fieldViolations' => []],
                ],
            ],
        ];

        // Falls back to the generic status, which here happens to be a code
        // this enum knows.
        self::assertSame(FcmErrorCode::InvalidArgument, FcmErrorCode::fromResponse($body));
    }

    public function test_an_empty_or_odd_body_yields_null(): void
    {
        self::assertNull(FcmErrorCode::fromResponse([]));
        self::assertNull(FcmErrorCode::fromResponse(['error' => 'not an array']));
        self::assertNull(FcmErrorCode::fromResponse(['error' => ['status' => 'SOMETHING_NEW']]));
    }

    public function test_quota_carries_firebases_published_minimum_backoff(): void
    {
        self::assertSame(
            60,
            FcmErrorCode::QuotaExceeded->minimumBackoffSeconds(),
            'Firebase: "Use a minimum initial delay of 1 minute"',
        );
        self::assertSame(0, FcmErrorCode::Unavailable->minimumBackoffSeconds());
    }

    public function test_the_documented_http_statuses_are_recorded(): void
    {
        self::assertSame(404, FcmErrorCode::Unregistered->httpStatus());
        self::assertSame(403, FcmErrorCode::SenderIdMismatch->httpStatus());
        self::assertSame(401, FcmErrorCode::ThirdPartyAuthError->httpStatus());
        self::assertSame(429, FcmErrorCode::QuotaExceeded->httpStatus());
        self::assertNull(FcmErrorCode::UnspecifiedError->httpStatus());
    }

    public function test_the_message_is_extracted_for_an_operator(): void
    {
        self::assertSame(
            'Requested entity was not found.',
            FcmErrorCode::messageFrom(['error' => ['message' => 'Requested entity was not found.']]),
        );
        self::assertSame('', FcmErrorCode::messageFrom([]));
    }
}
