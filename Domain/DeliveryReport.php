<?php

declare(strict_types=1);

namespace Plugins\Push\Domain;

/**
 * What one provider said about one notification.
 *
 * Carries the provider's own identifiers — `apns-id`, FCM's
 * `projects/*​/messages/{id}` — because they are the only handle a support
 * request can use. "The notification did not arrive" is unanswerable; "APNs
 * accepted 8a2f1e44-… at 14:02" is something Apple can look up.
 */
final readonly class DeliveryReport
{
    private function __construct(
        public DeliveryOutcome $outcome,
        public Provider $provider,
        /** The provider's reason code, verbatim. '' when it succeeded. */
        public string $reason,
        /** One line for a human. Never a credential, never a whole token. */
        public string $detail,
        /** The provider's id for this notification, for a support request. */
        public string $providerMessageId,
        /** Seconds to wait before retrying, when the provider said. */
        public int $retryAfter,
        public int $httpStatus,
        /**
         * Whether {@see self::$provider} describes a real routing decision.
         *
         * False for a refusal raised BEFORE a provider was chosen, where the
         * field carries a value only because it is not nullable. See
         * {@see self::refusedBeforeRouting()}.
         */
        private bool $providerKnown = true,
    ) {
    }

    public static function delivered(Provider $provider, string $messageId = ''): self
    {
        return new self(
            DeliveryOutcome::Delivered,
            $provider,
            '',
            'accepted by ' . $provider->label(),
            $messageId,
            0,
            200,
        );
    }

    public static function of(
        DeliveryOutcome $outcome,
        Provider $provider,
        string $reason,
        string $detail,
        int $httpStatus = 0,
        int $retryAfter = 0,
        string $messageId = '',
    ): self {
        return new self($outcome, $provider, $reason, $detail, $messageId, max(0, $retryAfter), $httpStatus);
    }

    /**
     * A transport failure — nothing was heard back at all.
     *
     * Always {@see DeliveryOutcome::Retry}: a connection that failed says
     * nothing about whether the notification was good, and the one thing it
     * must not do is make the caller discard a valid token.
     */
    public static function unreachable(Provider $provider, string $detail): self
    {
        return new self(
            DeliveryOutcome::Retry,
            $provider,
            'transport',
            $detail,
            '',
            0,
            0,
        );
    }

    /** A send that was never attempted, because the plugin is off or in dry run. */
    public static function skipped(Provider $provider, string $why): self
    {
        return new self(DeliveryOutcome::Delivered, $provider, 'skipped', $why, '', 0, 0);
    }

    /**
     * Refused before a provider was ever chosen — an unregistered token, a row
     * that could not be read.
     *
     * A provider still has to be carried, because the field is not nullable,
     * but {@see self::providerIsKnown()} says it means nothing here. Without
     * that, `push:send` on an unregistered token printed
     * "Provider: FCM HTTP v1" — a provider nothing had selected, and a small
     * untruth that sends someone looking for why their iOS token went to
     * Google.
     */
    public static function refusedBeforeRouting(string $reason, string $detail): self
    {
        return new self(
            DeliveryOutcome::Rejected,
            Provider::Fcm,
            $reason,
            $detail,
            '',
            0,
            0,
            providerKnown: false,
        );
    }

    /** Whether {@see self::$provider} describes a real routing decision. */
    public function providerIsKnown(): bool
    {
        return $this->providerKnown;
    }

    public function isDelivered(): bool
    {
        return $this->outcome === DeliveryOutcome::Delivered;
    }

    /** One line for a log. */
    public function summary(): string
    {
        return \sprintf(
            '%s: %s%s',
            $this->provider->label(),
            $this->outcome->value,
            $this->reason !== '' ? ' (' . $this->reason . ')' : '',
        );
    }
}
