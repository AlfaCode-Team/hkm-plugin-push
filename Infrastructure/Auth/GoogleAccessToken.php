<?php

declare(strict_types=1);

namespace Plugins\Push\Infrastructure\Auth;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HttpClientPort;
use Plugins\Push\Domain\Exceptions\PushException;
use Plugins\Push\Domain\Jwt;
use Plugins\Push\Infrastructure\Config\PushConfig;

/**
 * An OAuth2 access token for FCM, from a service-account assertion.
 *
 * TWO HOPS, NOT ONE
 * -----------------
 * Unlike APNs — where the JWT IS the credential — Google's JWT is only an
 * assertion that has to be EXCHANGED for an access token. So sending one FCM
 * notification with a cold cache is two HTTP requests, and the first one is to
 * a different host. That matters for two reasons an operator will meet:
 *
 *   - a firewall that allows `fcm.googleapis.com` and not
 *     `oauth2.googleapis.com` fails with a connect error that names the wrong
 *     service entirely;
 *   - the exchange is the only request that can tell you a service-account key
 *     is wrong. FCM itself answers 401 without saying why.
 *
 * The flow, verified against Google's service-account documentation:
 * `POST https://oauth2.googleapis.com/token`, form-encoded,
 * `grant_type=urn:ietf:params:oauth:grant-type:jwt-bearer` plus
 * `assertion=<RS256 JWT>`. The JWT's `aud` is the token endpoint itself, its
 * `scope` is space-delimited, and `exp` may be at most one hour after `iat` —
 * "an `exp` more than 65 minutes after `iat` is rejected with
 * `invalid_grant`".
 *
 * CACHING, FOR THE SAME REASON AS APNs
 * ------------------------------------
 * A fresh process per request means an in-process cache never hits, so the
 * shared {@see CachePort} is the real one. Google's tokens last an hour; the
 * cache TTL is deliberately shorter than whatever `expires_in` says, so a
 * token is never presented in the last seconds of its life to a request that
 * then takes a while.
 */
final class GoogleAccessToken
{
    /** Google's messaging scope, from the send method's own reference. */
    public const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    /** Shaved off `expires_in` so a token is never used at the edge of expiry. */
    private const EXPIRY_MARGIN = 120;

    private ?string $memoised = null;
    private int $memoisedUntil = 0;

    public function __construct(
        private readonly PushConfig $config,
        private readonly HttpClientPort $http,
        private readonly ?CachePort $cache = null,
        private readonly ?\Closure $clock = null,
    ) {
    }

    public function isShared(): bool
    {
        return $this->cache !== null;
    }

    public function header(): string
    {
        return 'Bearer ' . $this->token();
    }

    /**
     * @throws PushException when the exchange fails
     */
    public function token(): string
    {
        $this->config->assertFcmUsable();

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

        [$token, $ttl] = $this->exchange($now);

        if ($this->cache !== null) {
            $this->cache->set($key, $token, $ttl);
        } else {
            $this->memoised      = $token;
            $this->memoisedUntil = $now + $ttl;
        }

        return $token;
    }

    public function forget(): void
    {
        $this->memoised      = null;
        $this->memoisedUntil = 0;

        $this->cache?->delete($this->cacheKey());
    }

    /**
     * Mint the assertion and exchange it.
     *
     * @return array{0: string, 1: int} the access token and its cache TTL
     * @throws PushException
     */
    private function exchange(int $now): array
    {
        $account = $this->config->serviceAccount();

        $assertion = Jwt::rs256(
            [
                'iss'   => $account['client_email'] ?? '',
                'scope' => self::SCOPE,
                'aud'   => $this->config->fcmOauthEndpoint,
                'iat'   => $now,
                // Exactly one hour. Google's ceiling, and anything past 65
                // minutes is rejected as invalid_grant.
                'exp'   => $now + 3600,
            ],
            $account['private_key'] ?? '',
            $account['private_key_id'] ?? '',
        );

        $response = $this->http->request('POST', $this->config->fcmOauthEndpoint, [
            'form' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $assertion,
            ],
            'timeout'         => $this->config->timeout,
            'connect_timeout' => $this->config->connectTimeout,
        ]);

        $body = $response->json();
        $body = \is_array($body) ? $body : [];

        if (!$response->ok()) {
            // Google's error body is {error, error_description} and the
            // description is the only thing that distinguishes a clock skew
            // from a revoked key — both of which are `invalid_grant`.
            throw PushException::misconfigured(
                'PUSH_FCM_CREDENTIALS',
                \sprintf(
                    'was refused by Google when exchanging the service-account assertion for an access '
                    . 'token (HTTP %d: %s%s). %s',
                    $response->status(),
                    \is_string($body['error'] ?? null) ? $body['error'] : 'no error code',
                    \is_string($body['error_description'] ?? null) ? ' — ' . $body['error_description'] : '',
                    ($body['error'] ?? '') === 'invalid_grant'
                        ? 'invalid_grant has two common causes that look identical: the service-account '
                          . 'key has been revoked or deleted in the Google console, or this machine\'s '
                          . 'clock is wrong. Check the clock first — it is the cheaper of the two to rule '
                          . 'out, and a skew of more than a few minutes invalidates every assertion.'
                        : 'This is the only request that can tell you the key itself is wrong; FCM '
                          . 'answers 401 without saying why.',
                ),
            );
        }

        $token = $body['access_token'] ?? null;

        if (!\is_string($token) || $token === '') {
            throw PushException::misconfigured(
                'PUSH_FCM_OAUTH_ENDPOINT',
                'answered 2xx with no access_token. If this is pointing at something other than '
                . 'https://oauth2.googleapis.com/token, that something is not an OAuth2 token endpoint.',
            );
        }

        $expiresIn = $body['expires_in'] ?? 3600;
        $expiresIn = \is_numeric($expiresIn) ? (int) $expiresIn : 3600;

        // Trust `expires_in` but never to the last second, and never longer
        // than an hour whatever it says.
        $ttl = max(60, min(3600, $expiresIn) - self::EXPIRY_MARGIN);

        return [$token, $ttl];
    }

    /** Scoped by service account, so rotating the key does not serve a stale token. */
    private function cacheKey(): string
    {
        $account = $this->config->serviceAccount();

        return 'push:fcm:access-token:' . ($account['client_email'] ?? 'unknown')
            . ':' . substr(hash('sha256', $account['private_key_id'] ?? ''), 0, 16);
    }

    private function now(): int
    {
        return $this->clock !== null ? (int) ($this->clock)() : time();
    }
}
