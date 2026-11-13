<?php

declare(strict_types=1);

namespace Plugins\Push\Domain;

/**
 * Every `reason` string APNs returns, and what to do about it.
 *
 * Transcribed from Apple's "Handling notification responses from APNs" — the
 * full table, not the memorable half of it. Two entries in here are the ones
 * that get missed, and both are expensive:
 *
 *   - **410 has TWO reasons**, `Unregistered` AND `ExpiredToken`. Code that
 *     only knows `Unregistered` keeps a dead token forever, because the status
 *     matched and the reason did not.
 *   - **403 has `UnrelatedKeyIdInToken`**: "The key ID in the provider token
 *     isn't related to the key ID of the token used in the first push of this
 *     connection. To use this token, open a new connection." That is not a
 *     credential problem, it is a CONNECTION problem — the fix is to reconnect,
 *     and treating it as bad credentials sends an operator to rotate a key that
 *     was never wrong.
 *
 * `BadDeviceToken` is mapped to {@see DeliveryOutcome::TokenInvalid} with one
 * eye open: Apple says "Verify that the request contains a valid token and
 * that the token matches the environment", so it is ALSO what a
 * production-vs-sandbox mix-up looks like. Pruning is still the right default —
 * a token that cannot be used from this configuration is useless to it — but
 * {@see self::mayBeEnvironmentMismatch()} exists so the caller can say so in
 * the log instead of silently shrinking the device table.
 */
enum ApnsReason: string
{
    // ── 400, the request is wrong ────────────────────────────────────────────
    case BadCollapseId         = 'BadCollapseId';
    case BadDeviceToken        = 'BadDeviceToken';
    case BadExpirationDate     = 'BadExpirationDate';
    case BadMessageId          = 'BadMessageId';
    case BadPriority           = 'BadPriority';
    case BadTopic              = 'BadTopic';
    case DeviceTokenNotForTopic = 'DeviceTokenNotForTopic';
    case DuplicateHeaders      = 'DuplicateHeaders';
    case IdleTimeout           = 'IdleTimeout';
    case InvalidPushType       = 'InvalidPushType';
    case MissingDeviceToken    = 'MissingDeviceToken';
    case MissingTopic          = 'MissingTopic';
    case PayloadEmpty          = 'PayloadEmpty';
    case TopicDisallowed       = 'TopicDisallowed';

    // ── 403, credentials or connection ───────────────────────────────────────
    case BadCertificate            = 'BadCertificate';
    case BadCertificateEnvironment = 'BadCertificateEnvironment';
    case ExpiredProviderToken      = 'ExpiredProviderToken';
    case Forbidden                 = 'Forbidden';
    case InvalidProviderToken      = 'InvalidProviderToken';
    case MissingProviderToken      = 'MissingProviderToken';
    case UnrelatedKeyIdInToken     = 'UnrelatedKeyIdInToken';
    case BadEnvironmentKeyIdInToken = 'BadEnvironmentKeyIdInToken';

    // ── 404 / 405 ────────────────────────────────────────────────────────────
    case BadPath          = 'BadPath';
    case MethodNotAllowed = 'MethodNotAllowed';

    // ── 410, the token is gone ───────────────────────────────────────────────
    case ExpiredToken  = 'ExpiredToken';
    case Unregistered  = 'Unregistered';

    // ── 413 / 429 / 5xx ──────────────────────────────────────────────────────
    case PayloadTooLarge             = 'PayloadTooLarge';
    case TooManyProviderTokenUpdates = 'TooManyProviderTokenUpdates';
    case TooManyRequests             = 'TooManyRequests';
    case InternalServerError         = 'InternalServerError';
    case ServiceUnavailable          = 'ServiceUnavailable';
    case Shutdown                    = 'Shutdown';

    public function outcome(): DeliveryOutcome
    {
        return match ($this) {
            // The token itself is finished.
            self::Unregistered,
            self::ExpiredToken,
            self::BadDeviceToken,
            self::DeviceTokenNotForTopic => DeliveryOutcome::TokenInvalid,

            // Transient: the same request, later, can work.
            self::TooManyRequests,
            self::TooManyProviderTokenUpdates,
            self::InternalServerError,
            self::ServiceUnavailable,
            self::Shutdown,
            // "Idle timeout" is the connection going away under us, not a bad
            // notification — Apple's guidance is to reconnect and resend.
            self::IdleTimeout,
            // A stale provider token is our own fault and self-healing: the
            // next attempt mints a fresh one, so this is a retry rather than
            // something to wake an operator for.
            self::ExpiredProviderToken => DeliveryOutcome::Retry,

            // A new CONNECTION fixes this one, so it is retryable — but only
            // because this plugin opens one per request. See the class
            // docblock; on a pooled connection it would need a reconnect first.
            self::UnrelatedKeyIdInToken => DeliveryOutcome::Retry,

            // Everything else needs a human: wrong topic, wrong key, wrong
            // payload, wrong push type.
            default => DeliveryOutcome::Rejected,
        };
    }

    /**
     * Whether this reason is also what a production/sandbox mix-up looks like.
     *
     * Worth reporting, because the token may be perfectly good and simply
     * belong to the other environment — and the device table shrinking is a
     * much quieter symptom than a configuration error.
     */
    public function mayBeEnvironmentMismatch(): bool
    {
        return match ($this) {
            self::BadDeviceToken, self::BadCertificateEnvironment, self::BadEnvironmentKeyIdInToken => true,
            default => false,
        };
    }

    /** Whether this points at the plugin's own credentials rather than the message. */
    public function isCredentialProblem(): bool
    {
        return match ($this) {
            self::BadCertificate,
            self::BadCertificateEnvironment,
            self::ExpiredProviderToken,
            self::InvalidProviderToken,
            self::MissingProviderToken,
            self::UnrelatedKeyIdInToken,
            self::BadEnvironmentKeyIdInToken => true,
            default                          => false,
        };
    }

    /** One line an operator can act on. */
    public function explain(): string
    {
        return match ($this) {
            self::BadDeviceToken => 'APNs says the token is invalid. Apple adds: "Verify that the request '
                . 'contains a valid token and that the token matches the environment." So this is ALSO what '
                . 'a sandbox token sent to the production host looks like — check the device\'s recorded '
                . 'environment before concluding the token is dead.',
            self::DeviceTokenNotForTopic => 'The token was issued for a different bundle id than the '
                . 'apns-topic sent with it. Either PUSH_APNS_TOPIC belongs to another app, or the device '
                . 'registered against a different build.',
            self::TopicDisallowed => 'This topic is not allowed for this key. A push type that needs a topic '
                . 'SUFFIX (VoIP, Live Activity) sent to the bare bundle id lands here.',
            self::MissingTopic    => 'No apns-topic was sent. Apple marks the header Required.',
            self::InvalidProviderToken => 'The provider token did not verify. The signing key, the key id '
                . '(PUSH_APNS_KEY_ID) and the team id (PUSH_APNS_TEAM_ID) have to belong together — a key '
                . 'id from one team and a key from another produces exactly this.',
            self::ExpiredProviderToken => 'The provider token was more than an hour old. Apple: "APNs '
                . 'rejects any request whose token contains a timestamp that\'s more than one hour old." '
                . 'The next attempt mints a new one.',
            self::TooManyProviderTokenUpdates => 'Provider tokens are being minted too often. Apple: '
                . '"Update the authentication token no more than once every 20 minutes." This means the '
                . 'token cache is not being shared between processes — check that a CachePort is bound.',
            self::UnrelatedKeyIdInToken => 'The signing key changed mid-connection. Apple: "To use this '
                . 'token, open a new connection." Harmless here, because each request is its own '
                . 'connection, and retrying resolves it.',
            self::PayloadTooLarge => 'The payload exceeded 4096 bytes (5120 for VoIP). APNs also forbids a '
                . 'compressed payload, so there is no way to buy headroom except sending less.',
            self::Unregistered, self::ExpiredToken => 'The app was uninstalled, or notifications were '
                . 'turned off. The token has been removed.',
            self::TooManyRequests => 'Too many notifications to this one device token. Back off.',
            self::InternalServerError, self::ServiceUnavailable, self::Shutdown => 'APNs is having trouble. '
                . 'Retry with backoff.',
            default => 'APNs refused the request: ' . $this->value . '.',
        };
    }

    /** Parse a reason string, or null when APNs sent one this build does not know. */
    public static function parse(?string $reason): ?self
    {
        if ($reason === null || trim($reason) === '') {
            return null;
        }

        return self::tryFrom(trim($reason));
    }
}
