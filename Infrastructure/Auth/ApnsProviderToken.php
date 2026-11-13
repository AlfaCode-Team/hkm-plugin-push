<?php

declare(strict_types=1);

namespace Plugins\Push\Infrastructure\Auth;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;
use Plugins\Push\Domain\Jwt;
use Plugins\Push\Infrastructure\Config\PushConfig;

/**
 * The APNs provider token, minted once and SHARED.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * WHY CACHING IS CORRECTNESS HERE, NOT PERFORMANCE
 * ────────────────────────────────────────────────────────────────────────────
 * Apple sets a floor as well as a ceiling: "Refresh your token no more than
 * once every 20 minutes and no less than once every 60 minutes." Minting one
 * per notification breaks the floor, and the answer is
 * `429 TooManyProviderTokenUpdates` — "The provider's authentication token is
 * being updated too often. Update the authentication token no more than once
 * every 20 minutes."
 *
 * Under PHP-FPM that is not a hypothetical. Every request is a fresh process
 * with nothing in memory, so an in-process cache is no cache at all: a hundred
 * requests mint a hundred tokens, and APNs starts refusing. The cache therefore
 * has to be the SHARED one — a bound {@see CachePort} — and an in-process
 * fallback exists only so a CLI command or a test works without one, with
 * {@see self::isShared()} saying which is in use so a status command can warn.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * THE LIFETIME IS DERIVED FROM THE SPEC, NOT CONFIGURED
 * ────────────────────────────────────────────────────────────────────────────
 * The window is 20 to 60 minutes. 30 is used: comfortably past the floor, with
 * half an hour of headroom before the ceiling, so a token minted just before a
 * long request still verifies when the request ends. There is no setting for
 * it, because every value outside that window is a 403 or a 429 and no value
 * inside it is better than another.
 *
 * Note there is no `exp` claim. Apple's token carries `iss` and `iat` only, and
 * expiry is the AGE of `iat` — so the cache TTL is the only thing standing
 * between a 61-minute-old token and `403 ExpiredProviderToken`.
 */
final class ApnsProviderToken
{
    /**
     * 30 minutes: inside Apple's 20–60 minute window, with headroom at both
     * ends. See the class docblock for why this is not a setting.
     */
    public const TTL_SECONDS = 1800;

    /** Per-process fallback when no CachePort is bound. */
    private ?string $memoised = null;
    private int $memoisedUntil = 0;

    public function __construct(
        private readonly PushConfig $config,
        private readonly ?CachePort $cache = null,
        /** Injected so a test can drive the clock instead of sleeping. */
        private readonly ?\Closure $clock = null,
    ) {
    }

    /** Whether the token is shared across processes. False is a warning sign under FPM. */
    public function isShared(): bool
    {
        return $this->cache !== null;
    }

    /**
     * The bearer token for the `authorization` header.
     *
     * Apple: "The token is in Base64URL-encoded JWT format, and is specified as
     * `bearer <token data>`" — the word `bearer` is lower-case in Apple's own
     * example, and the header name is `authorization`.
     */
    public function header(): string
    {
        return 'bearer ' . $this->token();
    }

    public function token(): string
    {
        $this->config->assertApnsUsable();

        $key = $this->cacheKey();
        $now = $this->now();

        if ($this->cache !== null) {
            $cached = $this->cache->get($key);

            if (\is_string($cached) && $cached !== '') {
                return $cached;
            }
        } elseif ($this->memoised !== null && $now < $this->memoisedUntil) {
            return $this->memoised;
        }

        // Apple defines exactly two claims and no more.
        $token = Jwt::es256(
            ['iss' => $this->config->apnsTeamId, 'iat' => $now],
            $this->config->apnsKey,
            $this->config->apnsKeyId,
        );

        if ($this->cache !== null) {
            $this->cache->set($key, $token, self::TTL_SECONDS);
        } else {
            $this->memoised      = $token;
            $this->memoisedUntil = $now + self::TTL_SECONDS;
        }

        return $token;
    }

    /**
     * Drop the cached token so the next call mints a fresh one.
     *
     * Called when APNs answers `ExpiredProviderToken`: the cache and Apple
     * disagree about the age of the token, and Apple is right. Without this the
     * cached token keeps being presented until its own TTL runs out, so every
     * notification in that window fails for a reason that has already been
     * diagnosed.
     */
    public function forget(): void
    {
        $this->memoised      = null;
        $this->memoisedUntil = 0;

        $this->cache?->delete($this->cacheKey());
    }

    /**
     * The cache key is scoped by KEY ID and TEAM ID.
     *
     * Not a constant, because rotating `PUSH_APNS_KEY_ID` must not keep
     * serving a token signed by the old key — that is
     * `403 UnrelatedKeyIdInToken` or `InvalidProviderToken`, arriving after a
     * deploy that looked clean, for as long as the old TTL lasts.
     */
    private function cacheKey(): string
    {
        return 'push:apns:provider-token:' . $this->config->apnsTeamId . ':' . $this->config->apnsKeyId;
    }

    private function now(): int
    {
        return $this->clock !== null ? (int) ($this->clock)() : time();
    }
}
