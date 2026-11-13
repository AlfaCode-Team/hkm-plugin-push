<?php

declare(strict_types=1);

namespace Plugins\Push\Domain;

/**
 * Which push network a device token belongs to.
 *
 * WHY THE PLATFORM IS STORED AND NOT GUESSED
 * ------------------------------------------
 * An APNs device token and an FCM registration token are both opaque strings,
 * and nothing in either one says which network issued it. They differ in shape
 * — APNs tokens are hex, FCM tokens are long and base64url-ish — but shape is
 * not a contract: Apple has changed token length before, and guessing wrong
 * means every notification for that device goes to a provider that has never
 * heard of it. So the platform is recorded at registration, by the client that
 * knows, and is never inferred here.
 *
 * `Web` is FCM's Web Push path. It takes the same FCM send call as Android, so
 * it is a separate case only because a `webpush` block in the message is not
 * interchangeable with an `android` one.
 */
enum Platform: string
{
    case Ios     = 'ios';
    case Android = 'android';
    case Web     = 'web';

    /** Which provider delivers for this platform. */
    public function provider(): Provider
    {
        return match ($this) {
            self::Ios              => Provider::Apns,
            self::Android, self::Web => Provider::Fcm,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Ios     => 'iOS',
            self::Android => 'Android',
            self::Web     => 'Web',
        };
    }

    /**
     * An iOS device may ALSO be addressed through FCM, when the app embeds the
     * Firebase SDK rather than registering with APNs directly.
     *
     * That is why {@see self::provider()} is a default and not a law: a project
     * whose iOS app uses Firebase registers its devices as `ios` with an FCM
     * token, and the router has to honour the token's own provider rather than
     * the platform's usual one. {@see DeviceToken::$provider} carries it.
     */
    public function mayUseFcm(): bool
    {
        return true;
    }
}
