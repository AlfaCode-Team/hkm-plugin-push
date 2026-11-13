<?php

declare(strict_types=1);

namespace Plugins\Push\Domain;

use Plugins\Push\Domain\Exceptions\PushException;

/**
 * Minimal JWT signing for the two provider handshakes.
 *
 * WHY THIS IS HERE AND NOT A COMPOSER DEPENDENCY
 * ----------------------------------------------
 * Signing is roughly forty lines, and a plugin's composer requirement has to
 * be installed by every project that enables the plugin — so a dependency for
 * forty lines is a deployment step for every consumer, forever. The one part
 * that genuinely needs care is the ES256 signature format, and it is explained
 * below rather than taken on trust.
 *
 * The TESTS do use a third-party library (`firebase/php-jwt`) as an oracle:
 * tokens are signed here and verified there. That is deliberate — a codec
 * checked against its own decoder proves only that it is self-consistent, and
 * the whole point of a signature is that somebody else can verify it.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * THE ES256 TRAP
 * ────────────────────────────────────────────────────────────────────────────
 * `openssl_sign()` with SHA256 over an EC key produces a DER-encoded SEQUENCE
 * of two INTEGERs (r and s). JOSE does not want that: RFC 7518 specifies the
 * ES256 signature as the raw concatenation `R || S`, each **exactly 32 bytes**,
 * left-padded.
 *
 * Hand DER straight to APNs and you get `403 InvalidProviderToken`, which reads
 * exactly like a wrong key — so the natural response is to re-download the .p8
 * and try again, which cannot work. Worse, DER integers are variable length and
 * carry a leading zero byte when the high bit is set, so a naive conversion
 * that merely strips the header produces a signature that is RIGHT most of the
 * time and wrong for about one key-and-payload combination in 128. That is an
 * intermittent authentication failure, which is the worst possible flavour.
 */
final class Jwt
{
    /**
     * Sign an RS256 JWT — Google's service-account assertion.
     *
     * Verified against Google's service-account documentation: `alg` RS256
     * ("the only supported value"), `typ` JWT, optional `kid`.
     *
     * @param array<string, mixed> $claims
     * @param string               $privateKey PEM
     * @throws PushException
     */
    public static function rs256(array $claims, string $privateKey, string $keyId = ''): string
    {
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];

        if ($keyId !== '') {
            $header['kid'] = $keyId;
        }

        $signingInput = self::encode($header) . '.' . self::encode($claims);
        $key          = self::loadKey($privateKey);
        $signature    = '';

        if (!openssl_sign($signingInput, $signature, $key, \OPENSSL_ALGO_SHA256)) {
            throw PushException::signingFailed(
                'openssl_sign refused the RSA key: ' . self::opensslErrors(),
            );
        }

        return $signingInput . '.' . self::base64Url($signature);
    }

    /**
     * Sign an ES256 JWT — the APNs provider token.
     *
     * Apple, verbatim: "APNs supports only the ES256 algorithm, so set the
     * value of this key to `ES256`" and `kid` is "The 10-character Key ID you
     * obtained from your developer account".
     *
     * Note what is NOT here: an `exp` claim. Apple's token has only `iss` and
     * `iat` — "The page defines no other claims" — and the lifetime is derived
     * from the AGE of `iat`: "APNs rejects any request whose token contains a
     * timestamp that's more than one hour old." Adding `exp` is not an error,
     * but believing it is what governs expiry is: a token with a two-hour `exp`
     * and a 61-minute-old `iat` is dead, and the `exp` says otherwise.
     *
     * @param array<string, mixed> $claims
     * @param string               $privateKey PEM (the contents of the .p8)
     * @throws PushException
     */
    public static function es256(array $claims, string $privateKey, string $keyId): string
    {
        $signingInput = self::encode(['alg' => 'ES256', 'kid' => $keyId])
            . '.' . self::encode($claims);

        $key = self::loadKey($privateKey);
        $der = '';

        if (!openssl_sign($signingInput, $der, $key, \OPENSSL_ALGO_SHA256)) {
            throw PushException::signingFailed(
                'openssl_sign refused the EC key: ' . self::opensslErrors()
                . '. An APNs key is an ES256 (P-256) private key — the .p8 downloaded from the Apple '
                . 'Developer portal, used verbatim including its BEGIN PRIVATE KEY lines.',
            );
        }

        return $signingInput . '.' . self::base64Url(self::derToJose($der, 32));
    }

    /**
     * DER `SEQUENCE { INTEGER r, INTEGER s }` → raw `R || S`, each $size bytes.
     *
     * Parsed properly rather than by offset arithmetic, because the lengths are
     * variable: an INTEGER whose high bit is set is prefixed with a 0x00 byte
     * so it is not read as negative, and one with leading zero bytes is encoded
     * shorter. Both cases are common enough to matter and rare enough to pass
     * a cursory test.
     *
     * @throws PushException when the signature is not the DER this expects
     */
    public static function derToJose(string $der, int $size): string
    {
        $offset = 0;
        $length = \strlen($der);

        $byte = static function () use ($der, &$offset, $length): int {
            if ($offset >= $length) {
                throw PushException::signingFailed(
                    'the DER signature ended early — openssl produced something this parser does not '
                    . 'understand.',
                );
            }

            return \ord($der[$offset++]);
        };

        if ($byte() !== 0x30) {
            throw PushException::signingFailed('the DER signature does not start with a SEQUENCE tag.');
        }

        $seqLength = $byte();

        if ($seqLength > 0x80) {
            // A long-form length. A P-256 signature is ~70 bytes so this never
            // happens in practice, but reading the byte as a length when it is
            // a length-of-length would silently misparse.
            $bytes     = $seqLength & 0x7F;
            $seqLength = 0;

            for ($i = 0; $i < $bytes; $i++) {
                $seqLength = ($seqLength << 8) | $byte();
            }
        }

        $readInt = static function () use ($byte, $size): string {
            if ($byte() !== 0x02) {
                throw PushException::signingFailed('the DER signature is missing an INTEGER tag.');
            }

            $intLength = $byte();
            $value     = '';

            for ($i = 0; $i < $intLength; $i++) {
                $value .= \chr($byte());
            }

            // Strip the sign byte DER adds when the high bit is set.
            $value = ltrim($value, "\x00");

            if (\strlen($value) > $size) {
                throw PushException::signingFailed(\sprintf(
                    'a DER INTEGER is %d bytes, which does not fit a %d-byte JOSE component.',
                    \strlen($value),
                    $size,
                ));
            }

            // Left-pad to the fixed width RFC 7518 requires.
            return str_pad($value, $size, "\x00", \STR_PAD_LEFT);
        };

        return $readInt() . $readInt();
    }

    /** @param array<string, mixed> $value */
    private static function encode(array $value): string
    {
        $json = json_encode($value, \JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw PushException::signingFailed('a JWT segment could not be encoded as JSON.');
        }

        return self::base64Url($json);
    }

    public static function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * @return \OpenSSLAsymmetricKey
     * @throws PushException
     */
    private static function loadKey(string $privateKey): \OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_get_private($privateKey);

        if ($key === false) {
            throw PushException::signingFailed(
                'openssl could not read the private key: ' . self::opensslErrors()
                . '. The value must be the PEM text itself, with its BEGIN/END lines and real newlines — '
                . 'an .env value whose "\\n" sequences were never unescaped is the usual cause, and it '
                . 'fails here rather than at the provider.',
            );
        }

        return $key;
    }

    /**
     * Drain openssl's error queue.
     *
     * Drained rather than peeked, because the queue is per-process and
     * cumulative: a stale entry from an unrelated earlier failure would
     * otherwise be reported as the cause of this one.
     */
    private static function opensslErrors(): string
    {
        $errors = [];

        while (($error = openssl_error_string()) !== false) {
            $errors[] = $error;
        }

        return $errors === [] ? '(no detail from openssl)' : implode('; ', $errors);
    }
}
