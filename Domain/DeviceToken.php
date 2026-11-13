<?php

declare(strict_types=1);

namespace Plugins\Push\Domain;

use Plugins\Push\Domain\Exceptions\PushException;

/**
 * One registered device: the address a notification is sent to.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * THE ONE HAZARD IN THIS PLUGIN THAT IS A PRIVACY BUG
 * ────────────────────────────────────────────────────────────────────────────
 * A device token identifies a DEVICE AND AN APP INSTALL. It does not identify a
 * person, and neither APNs nor FCM changes it when somebody signs out.
 *
 * So on a shared phone — a family tablet, a demo handset, a work device handed
 * over — this happens:
 *
 *   1. user A signs in. The app registers token T against user A.
 *   2. user A signs out. The token is STILL T; nothing told the push network
 *      anything, because signing out of an app is not a push-level event.
 *   3. user B signs in on the same device. The app registers token T again.
 *
 * If step 3 INSERTS, the table now maps T to both A and B, and every
 * notification for A is delivered to the phone B is holding. There is no error.
 * Both deliveries report success. The push network did exactly what it was
 * asked. And it cannot happen on a developer's own device, because a developer
 * signs in as themselves, so it reaches production untested.
 *
 * The defence has two halves and needs both:
 *
 *   - **the token is the IDENTITY of the row.** Registering a known token for
 *     a different user REASSIGNS it — one token, one owner, enforced by a
 *     unique index on the token and an upsert that overwrites `user_id`. This
 *     is why {@see DeviceRepository::register()} is an upsert on the token and
 *     not an insert.
 *   - **the app must call forget() on sign-out.** Reassignment only helps if
 *     somebody signs in afterwards. A device signed out and left alone keeps
 *     its old owner, so `push:status` warns when no token has ever been
 *     forgotten — a device table that only ever grows is the symptom.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * WHY THE PROVIDER IS STORED SEPARATELY FROM THE PLATFORM
 * ────────────────────────────────────────────────────────────────────────────
 * An iOS app may register with APNs directly, or through the Firebase SDK, and
 * the token is a different thing in each case — sent to the wrong network it is
 * simply unknown. The platform says what the device IS; the provider says who
 * issued this string. They are usually implied by each other and must not be
 * conflated, because the exception is a normal iOS app that happens to use
 * Firebase.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * fid AND token
 * ────────────────────────────────────────────────────────────────────────────
 * FCM's Message reference now marks the `token` target DEPRECATED — "Use `fid`
 * instead" — and accepts a Firebase Installation ID in either field during the
 * transition. Both are therefore carried: `fid` is sent when the client
 * supplied one, `token` otherwise, so a project migrating its app does not have
 * to migrate its server on the same day.
 */
final readonly class DeviceToken
{
    /** APNs tokens are hex. Checked loosely — see the constructor. */
    private const APNS_PATTERN = '/^[0-9a-fA-F]{32,200}$/';

    private function __construct(
        /** The provider's address for this device. Never logged in full. */
        public string $token,
        public Platform $platform,
        public Provider $provider,
        public PushEnvironment $environment,
        /** Who this device currently belongs to. '' for an anonymous install. */
        public string $userId,
        /** The Firebase Installation ID, when the client supplied one. */
        public string $fid,
        /** Free-form, for a status command: 'iPhone 15, app 2.4.1'. */
        public string $label,
    ) {
    }

    /**
     * @throws PushException when the token could not be an address at all
     */
    public static function of(
        string $token,
        Platform $platform,
        ?Provider $provider = null,
        ?PushEnvironment $environment = null,
        string $userId = '',
        string $fid = '',
        string $label = '',
    ): self {
        $token = trim($token);
        $fid   = trim($fid);

        if ($token === '' && $fid === '') {
            throw PushException::invalidToken(
                'a device needs either a provider token or a Firebase Installation ID, and this has '
                . 'neither — there is no address to send to.',
            );
        }

        $provider ??= $platform->provider();

        // Length is checked; SHAPE is not, beyond the APNs hex rule. A
        // registration token's format is the provider's business and both have
        // changed it: Apple's tokens grew from 32 to 100 bytes, and FCM tokens
        // have no published grammar at all. A validator stricter than the
        // provider rejects live devices the day the format moves, which is a
        // self-inflicted outage with no upside — the provider tells us within
        // one request if a token is wrong, and that answer is authoritative.
        if (\strlen($token) > 4096) {
            throw PushException::invalidToken(
                'the token is longer than 4096 bytes, which no provider issues — this is almost '
                . 'certainly a whole JSON body registered as a token.',
            );
        }

        if ($provider === Provider::Apns && $token !== '' && preg_match(self::APNS_PATTERN, $token) !== 1) {
            throw PushException::invalidToken(
                'an APNs device token is hexadecimal, and this is not. It goes into the request PATH '
                . '(/3/device/<token>), so a token with other characters in it would be a malformed '
                . 'URL rather than a refused notification.',
            );
        }

        return new self(
            token:       $token,
            platform:    $platform,
            provider:    $provider,
            environment: $environment ?? PushEnvironment::Production,
            userId:      trim($userId),
            fid:         $fid,
            label:       trim($label),
        );
    }

    /**
     * The FCM target: the FID when there is one, else the registration token.
     *
     * @return array{field: 'fid'|'token', value: string}
     */
    public function fcmTarget(): array
    {
        return $this->fid !== ''
            ? ['field' => 'fid', 'value' => $this->fid]
            : ['field' => 'token', 'value' => $this->token];
    }

    /**
     * A SAFE rendering for logs and status output.
     *
     * A device token is not a password, but it IS an address that lets anyone
     * holding it push arbitrary notifications to that handset given the app's
     * credentials — and it is personal data, because it identifies a device
     * across sessions. Logs get the ends and a length, never the middle.
     */
    public function masked(): string
    {
        $value = $this->token !== '' ? $this->token : $this->fid;
        $length = \strlen($value);

        if ($length <= 12) {
            return str_repeat('*', $length);
        }

        return substr($value, 0, 6) . '…' . substr($value, -4) . ' (' . $length . ' bytes)';
    }

    public function withUser(string $userId): self
    {
        return new self(
            $this->token,
            $this->platform,
            $this->provider,
            $this->environment,
            trim($userId),
            $this->fid,
            $this->label,
        );
    }

    /** The key a row is identified by — see the hazard note in the class docblock. */
    public function identity(): string
    {
        return $this->token !== '' ? $this->token : $this->fid;
    }
}
