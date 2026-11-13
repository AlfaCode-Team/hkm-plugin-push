<?php

declare(strict_types=1);

namespace Plugins\Push\Domain;

/**
 * What happened to one notification, for one token.
 *
 * FOUR OUTCOMES, BECAUSE THREE IS NOT ENOUGH
 * ------------------------------------------
 * "Worked / failed" loses the only distinction the caller has to act on, and
 * the loss is expensive in both directions:
 *
 *   - {@see self::Delivered} — the provider ACCEPTED it. Not "the user saw
 *     it": no push network tells you that, and treating acceptance as receipt
 *     is how a product grows a read-receipt feature that is quietly a lie.
 *   - {@see self::TokenInvalid} — the provider says this token is dead. The
 *     row must be DELETED, or the outbox retries it forever and every future
 *     fan-out pays for a device that no longer exists.
 *   - {@see self::Retry} — transient. Retrying is correct, with backoff, and
 *     the provider may have said when (`Retry-After`).
 *   - {@see self::Rejected} — the REQUEST is wrong: a bad topic, an oversized
 *     payload, bad credentials. Retrying an identical request cannot help, and
 *     the only useful response is to tell a human. Collapsing this into
 *     "retry" is how a misconfigured topic turns into a job that burns its
 *     attempts five times an hour, forever, with the real error buried.
 */
enum DeliveryOutcome: string
{
    case Delivered    = 'delivered';
    case TokenInvalid = 'token_invalid';
    case Retry        = 'retry';
    case Rejected     = 'rejected';

    /** Whether the device token must be removed. */
    public function prunesToken(): bool
    {
        return $this === self::TokenInvalid;
    }

    /** Whether sending the same request again could succeed. */
    public function isRetryable(): bool
    {
        return $this === self::Retry;
    }

    /** Whether a human has to change something before this can ever work. */
    public function needsOperator(): bool
    {
        return $this === self::Rejected;
    }

    public function label(): string
    {
        return match ($this) {
            self::Delivered    => 'accepted by the provider',
            self::TokenInvalid => 'the token is dead and was removed',
            self::Retry        => 'temporarily failed, will be retried',
            self::Rejected     => 'refused — the request or the configuration is wrong',
        };
    }
}
