<?php

declare(strict_types=1);

namespace Plugins\Push\Tests;

use Firebase\JWT\JWT as OracleJwt;
use Firebase\JWT\Key;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Push\Domain\Exceptions\PushException;
use Plugins\Push\Domain\Jwt;

/**
 * Provider-token signing, verified by a library this plugin did not write.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * WHY THE ORACLE MATTERS MORE THAN THE ASSERTIONS
 * ────────────────────────────────────────────────────────────────────────────
 * A codec checked against its own decoder proves only that it is
 * self-consistent. The entire purpose of a signature is that somebody ELSE can
 * verify it — so every signature here is handed to `firebase/php-jwt`, which is
 * a test-only dependency precisely so this can be done.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * THE ES256 PADDING CASE IS SWEPT, NOT SAMPLED
 * ────────────────────────────────────────────────────────────────────────────
 * openssl emits a DER SEQUENCE of two INTEGERs; JOSE wants raw `R || S` with
 * each component exactly 32 bytes. DER integers are variable length — shorter
 * when they have leading zeros, one byte longer when the high bit is set — so a
 * parser that strips a fixed header is correct for most keys and wrong for
 * roughly one in 128. An intermittent `403 InvalidProviderToken` is the worst
 * possible flavour of bug, so this signs 400 times with fresh keys and ASSERTS
 * that the padding-sensitive case actually occurred — a sweep that never hit it
 * would be a green test proving nothing.
 */
#[CoversClass(Jwt::class)]
final class JwtTest extends TestCase
{
    private static string $ecPrivate = '';
    private static string $ecPublic  = '';
    private static string $rsaPrivate = '';
    private static string $rsaPublic  = '';

    public static function setUpBeforeClass(): void
    {
        $ec = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => \OPENSSL_KEYTYPE_EC]);
        self::assertNotFalse($ec, 'this build of openssl cannot generate a P-256 key');
        openssl_pkey_export($ec, $private);
        self::$ecPrivate = (string) $private;
        self::$ecPublic  = (string) (openssl_pkey_get_details($ec)['key'] ?? '');

        $rsa = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($rsa);
        openssl_pkey_export($rsa, $rsaPrivate);
        self::$rsaPrivate = (string) $rsaPrivate;
        self::$rsaPublic  = (string) (openssl_pkey_get_details($rsa)['key'] ?? '');
    }

    // ── ES256, the APNs provider token ──────────────────────────────────────

    public function test_an_es256_token_verifies_in_a_library_we_did_not_write(): void
    {
        $token   = Jwt::es256(['iss' => 'DEF123GHIJ', 'iat' => 1_700_000_000], self::$ecPrivate, 'ABC123DEFG');
        $decoded = OracleJwt::decode($token, new Key(self::$ecPublic, 'ES256'));

        self::assertSame('DEF123GHIJ', $decoded->iss);
        self::assertSame(1_700_000_000, $decoded->iat);
    }

    public function test_the_header_is_exactly_what_apple_documents(): void
    {
        $token  = Jwt::es256(['iss' => 'T', 'iat' => 1], self::$ecPrivate, 'KEYID12345');
        $header = self::segment($token, 0);

        self::assertSame('ES256', $header['alg'], 'Apple: "APNs supports only the ES256 algorithm"');
        self::assertSame('KEYID12345', $header['kid']);
    }

    public function test_the_claim_set_carries_iss_and_iat_AND_NOTHING_ELSE(): void
    {
        // Apple defines only these two, and expiry is the AGE of iat — "APNs
        // rejects any request whose token contains a timestamp that's more than
        // one hour old." An `exp` here would read as the thing governing
        // expiry, and it is not.
        $claims = self::segment(Jwt::es256(['iss' => 'T', 'iat' => 1], self::$ecPrivate, 'K'), 1);

        self::assertSame(['iss', 'iat'], array_keys($claims));
    }

    public function test_the_signature_is_raw_R_concat_S_at_64_bytes(): void
    {
        $token     = Jwt::es256(['iss' => 'T', 'iat' => 1], self::$ecPrivate, 'K');
        $signature = self::rawSignature($token);

        self::assertSame(
            64,
            \strlen($signature),
            'RFC 7518 specifies ES256 as R||S, 32 bytes each. DER is longer and variable, and handing '
            . 'DER to APNs is a 403 InvalidProviderToken that reads exactly like a wrong key',
        );
    }

    public function test_a_tampered_payload_is_rejected_by_the_oracle(): void
    {
        $token = Jwt::es256(['iss' => 'GOOD000000', 'iat' => 1], self::$ecPrivate, 'K');
        [$header, , $signature] = explode('.', $token);

        $forged = $header . '.' . Jwt::base64Url('{"iss":"EVIL000000","iat":1}') . '.' . $signature;

        $this->expectException(\Firebase\JWT\SignatureInvalidException::class);
        OracleJwt::decode($forged, new Key(self::$ecPublic, 'ES256'));
    }

    public function test_400_signatures_all_verify_and_the_padding_case_really_occurs(): void
    {
        $padded = 0;

        for ($i = 0; $i < 400; $i++) {
            $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => \OPENSSL_KEYTYPE_EC]);
            self::assertNotFalse($key, "openssl failed to generate key {$i} of 400");
            openssl_pkey_export($key, $private);
            $public = (string) (openssl_pkey_get_details($key)['key'] ?? '');

            $token     = Jwt::es256(['iss' => 'TEAM000000', 'iat' => $i], (string) $private, 'KEY0000000');
            $signature = self::rawSignature($token);

            self::assertSame(64, \strlen($signature), "signature {$i} was not 64 bytes");

            // A component that needed left-padding: its DER form was shorter
            // than 32 bytes. This is the case a naive parser gets wrong.
            if ($signature[0] === "\x00" || $signature[32] === "\x00") {
                $padded++;
            }

            // The oracle, every time — not just on the interesting ones.
            OracleJwt::decode($token, new Key($public, 'ES256'));
        }

        self::assertGreaterThan(
            0,
            $padded,
            'no padding-sensitive signature occurred in 400 rounds, so this run proved nothing about '
            . 'the DER conversion — the sweep has to be widened rather than trusted',
        );
    }

    // ── RS256, the Google service-account assertion ─────────────────────────

    public function test_an_rs256_assertion_verifies_in_the_oracle(): void
    {
        // A CURRENT iat/exp, because the oracle validates expiry as well as
        // the signature — which is worth knowing: a fixed 2023 timestamp here
        // failed with ExpiredException while the signature itself was fine, so
        // the oracle is checking more than the maths.
        $now = time();

        $token = Jwt::rs256(
            [
                'iss'   => 'svc@project.iam.gserviceaccount.com',
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud'   => 'https://oauth2.googleapis.com/token',
                'iat'   => $now,
                'exp'   => $now + 3600,
            ],
            self::$rsaPrivate,
            'key-id-1',
        );

        $decoded = OracleJwt::decode($token, new Key(self::$rsaPublic, 'RS256'));

        self::assertSame('https://oauth2.googleapis.com/token', $decoded->aud);
        self::assertSame(3600, $decoded->exp - $decoded->iat, 'Google caps the lifetime at one hour');
    }

    public function test_the_rs256_header_matches_googles_documentation(): void
    {
        $header = self::segment(Jwt::rs256(['iss' => 'x'], self::$rsaPrivate, 'kid-7'), 0);

        self::assertSame('RS256', $header['alg'], 'Google: RS256 is "the only supported value"');
        self::assertSame('JWT', $header['typ']);
        self::assertSame('kid-7', $header['kid']);
    }

    public function test_the_kid_is_omitted_when_there_is_none(): void
    {
        self::assertArrayNotHasKey('kid', self::segment(Jwt::rs256(['iss' => 'x'], self::$rsaPrivate), 0));
    }

    public function test_the_scope_is_not_url_escaped(): void
    {
        // JSON_UNESCAPED_SLASHES matters: an escaped scope is a different
        // string, and Google answers invalid_scope.
        $token = Jwt::rs256(['scope' => 'https://www.googleapis.com/auth/firebase.messaging'], self::$rsaPrivate);

        self::assertStringNotContainsString('%2F', $token);
        self::assertSame(
            'https://www.googleapis.com/auth/firebase.messaging',
            self::segment($token, 1)['scope'],
        );
    }

    // ── Refusals ────────────────────────────────────────────────────────────

    public function test_a_key_that_is_not_a_pem_is_refused_with_an_explanation(): void
    {
        try {
            Jwt::es256(['iss' => 'x', 'iat' => 1], 'this is not a pem', 'K');
            self::fail('garbage should not sign');
        } catch (PushException $e) {
            self::assertStringContainsString(
                'BEGIN',
                $e->getMessage(),
                'the most common cause is a .env value whose \\n were never unescaped, so the message '
                . 'has to say what the key should look like',
            );
        }
    }

    public function test_an_rsa_key_cannot_sign_an_es256_token(): void
    {
        $this->expectException(PushException::class);
        Jwt::es256(['iss' => 'x', 'iat' => 1], self::$rsaPrivate, 'K');
    }

    public function test_a_malformed_der_signature_is_reported_not_silently_truncated(): void
    {
        $this->expectException(PushException::class);
        $this->expectExceptionMessage('SEQUENCE');

        Jwt::derToJose("\x02\x01\x00", 32);
    }

    public function test_base64url_has_no_padding_and_no_plus_or_slash(): void
    {
        $encoded = Jwt::base64Url(random_bytes(64));

        self::assertStringNotContainsString('=', $encoded);
        self::assertStringNotContainsString('+', $encoded);
        self::assertStringNotContainsString('/', $encoded);
    }

    /** @return array<string, mixed> */
    private static function segment(string $token, int $index): array
    {
        $parts   = explode('.', $token);
        $decoded = json_decode(
            (string) base64_decode(strtr($parts[$index], '-_', '+/'), true),
            true,
        );

        return \is_array($decoded) ? $decoded : [];
    }

    private static function rawSignature(string $token): string
    {
        $parts = explode('.', $token);

        return (string) base64_decode(strtr($parts[2], '-_', '+/'), true);
    }
}
