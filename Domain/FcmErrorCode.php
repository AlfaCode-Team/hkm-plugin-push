<?php

declare(strict_types=1);

namespace Plugins\Push\Domain;

/**
 * Every FCM HTTP v1 error code, and what to do about it.
 *
 * Transcribed from Firebase's ErrorCode reference, including the HTTP status
 * each one arrives with — because the status alone is not enough to decide.
 * `404` is the clearest case: on this API it means UNREGISTERED, which is "the
 * token is dead, delete it", and NOT "the endpoint is wrong". A generic HTTP
 * client that treats 404 as a programming error keeps every dead token.
 *
 * The one to be careful with is `INVALID_ARGUMENT` (400). Firebase lists it for
 * a bad registration token, a bad package name, an oversized payload, a bad
 * data key, a bad TTL and bad parameter names or types — six causes, one code,
 * and only one of them is the token's fault. Its "Manage registration tokens"
 * page names `UNREGISTERED` and `INVALID_ARGUMENT` as the two codes for an
 * invalid or expired registration, while warning that `INVALID_ARGUMENT` "can
 * also be returned in cases of issues in the message payload".
 *
 * So the code alone cannot decide, and the MESSAGE is the discriminator.
 * Firebase's own example for a bad token carries
 * `"message": "The registration token is not a valid FCM registration token"`.
 * {@see self::indicatesInvalidToken()} looks for that, and only that, prunes.
 * Everything else under `INVALID_ARGUMENT` is {@see DeliveryOutcome::Rejected}
 * — a human reads it — rather than the device table quietly losing rows
 * because a payload field was misspelled.
 */
enum FcmErrorCode: string
{
    case UnspecifiedError   = 'UNSPECIFIED_ERROR';
    case InvalidArgument    = 'INVALID_ARGUMENT';
    case Unregistered       = 'UNREGISTERED';
    case SenderIdMismatch   = 'SENDER_ID_MISMATCH';
    case QuotaExceeded      = 'QUOTA_EXCEEDED';
    case Unavailable        = 'UNAVAILABLE';
    case Internal           = 'INTERNAL';
    case ThirdPartyAuthError = 'THIRD_PARTY_AUTH_ERROR';

    /** The HTTP status Firebase documents for this code. */
    public function httpStatus(): ?int
    {
        return match ($this) {
            self::UnspecifiedError    => null,
            self::InvalidArgument     => 400,
            self::ThirdPartyAuthError => 401,
            self::SenderIdMismatch    => 403,
            self::Unregistered        => 404,
            self::QuotaExceeded       => 429,
            self::Internal            => 500,
            self::Unavailable         => 503,
        };
    }

    public function outcome(): DeliveryOutcome
    {
        return match ($this) {
            // The only code Firebase explicitly says to delete the token for:
            // "remove this registration token from the app server and stop
            // using it to send messages."
            self::Unregistered => DeliveryOutcome::TokenInvalid,

            // Firebase's own guidance is retry with exponential backoff, and
            // for QUOTA_EXCEEDED "a minimum initial delay of 1 minute".
            self::QuotaExceeded,
            self::Unavailable,
            self::Internal,
            self::UnspecifiedError => DeliveryOutcome::Retry,

            // INVALID_ARGUMENT: "Correct the invalid field." Not the token.
            // SENDER_ID_MISMATCH: the token belongs to a different Firebase
            //   project. Deleting it would be wrong — it is a perfectly good
            //   token and this is the wrong server for it.
            // THIRD_PARTY_AUTH_ERROR: the APNs certificate or web-push key
            //   configured INSIDE Firebase is bad, which no retry fixes.
            self::InvalidArgument,
            self::SenderIdMismatch,
            self::ThirdPartyAuthError => DeliveryOutcome::Rejected,
        };
    }

    /**
     * The minimum backoff Firebase asks for, in seconds.
     *
     * QUOTA_EXCEEDED is the one with a published floor — "Use a minimum initial
     * delay of 1 minute for message rate and topic message rate limits" — and
     * it is a floor worth honouring, because retrying a rate limit faster than
     * that is how a sender earns a longer one.
     */
    public function minimumBackoffSeconds(): int
    {
        return $this === self::QuotaExceeded ? 60 : 0;
    }

    /**
     * Whether this error means THIS TOKEN is dead, message included.
     *
     * `UNREGISTERED` always does. `INVALID_ARGUMENT` does only when the message
     * names the registration token — Firebase's own example being "The
     * registration token is not a valid FCM registration token" — because the
     * same code also covers payload, TTL and parameter faults, and pruning a
     * device because a data key was misspelled loses a registration that was
     * never at fault and cannot be recovered without the app running again.
     */
    public function indicatesInvalidToken(string $message = ''): bool
    {
        if ($this === self::Unregistered) {
            return true;
        }

        if ($this !== self::InvalidArgument) {
            return false;
        }

        $message = strtolower($message);

        // Both spellings Firebase uses for the same fault.
        return str_contains($message, 'registration token')
            || str_contains($message, 'not a valid fcm registration token');
    }

    /** Whether this points at the project's own Firebase configuration. */
    public function isConfigurationProblem(): bool
    {
        return match ($this) {
            self::SenderIdMismatch, self::ThirdPartyAuthError => true,
            default                                           => false,
        };
    }

    /** One line an operator can act on. */
    public function explain(): string
    {
        return match ($this) {
            self::Unregistered => 'The app instance is no longer registered with FCM, so the token is '
                . 'invalid. It has been removed.',
            self::InvalidArgument => 'FCM rejected a field in the request. Firebase lists six causes for '
                . 'this one code — a bad registration token, package name, payload size, data key, TTL, or '
                . 'parameter name/type — and its guidance is to CHECK the token format, not to delete it. '
                . 'The token was therefore kept; read the message to see which field it meant.',
            self::SenderIdMismatch => 'The authenticated sender id does not match the one this token was '
                . 'issued for. The token belongs to a different Firebase project, so PUSH_FCM_PROJECT_ID '
                . 'and the service-account credentials are for the wrong project — or this device '
                . 'registered against a different one. The token is good; this server is the wrong sender.',
            self::ThirdPartyAuthError => 'Firebase could not authenticate to APNs on your behalf: "The '
                . 'APNs certificate or web push auth key is invalid or missing." That credential lives in '
                . 'the Firebase console, not in this plugin\'s configuration.',
            self::QuotaExceeded => 'A message, device or topic rate limit was exceeded. Firebase asks for '
                . 'a minimum initial delay of one minute before retrying.',
            self::Unavailable => 'FCM is overloaded. Retry with backoff, honouring Retry-After if present.',
            self::Internal    => 'An unknown internal FCM error. Retry with backoff.',
            self::UnspecifiedError => 'FCM reported no detail at all. Treated as transient.',
        };
    }

    /**
     * Pull the error code out of an FCM error response body.
     *
     * The code lives at `error.details[].errorCode`, in the element whose
     * `@type` is `type.googleapis.com/google.firebase.fcm.v1.FcmError` —
     * verified against Firebase's own example responses.
     *
     * It is read from there and not from `error.status` because Firebase does
     * NOT document what `error.status` holds for `UNREGISTERED`. That is the
     * whole argument: the one code this plugin must recognise to prune a dead
     * token is the one whose generic status is unspecified, so a reader that
     * trusted `error.status` would be guessing on exactly the case that
     * matters. `details[]` is where Firebase says the code is.
     *
     * @param array<string, mixed> $body the decoded JSON response
     */
    public static function fromResponse(array $body): ?self
    {
        $error = $body['error'] ?? null;

        if (!\is_array($error)) {
            return null;
        }

        foreach ($error['details'] ?? [] as $detail) {
            if (!\is_array($detail)) {
                continue;
            }

            $type = $detail['@type'] ?? '';

            if (\is_string($type) && str_contains($type, 'FcmError')) {
                $code = $detail['errorCode'] ?? null;

                if (\is_string($code) && ($parsed = self::tryFrom($code)) !== null) {
                    return $parsed;
                }
            }
        }

        // Fall back to the generic status, which is right for everything except
        // the one case above.
        $status = $error['status'] ?? null;

        return \is_string($status) ? self::tryFrom($status) : null;
    }

    /**
     * The human message FCM supplied, if any — it is usually the only thing
     * that says WHICH field an INVALID_ARGUMENT meant.
     *
     * @param array<string, mixed> $body
     */
    public static function messageFrom(array $body): string
    {
        $error = $body['error'] ?? null;

        if (\is_array($error) && \is_string($error['message'] ?? null)) {
            return $error['message'];
        }

        return '';
    }
}
