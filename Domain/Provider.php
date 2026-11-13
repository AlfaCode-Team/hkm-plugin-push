<?php

declare(strict_types=1);

namespace Plugins\Push\Domain;

/** The push network a notification is handed to. */
enum Provider: string
{
    case Apns = 'apns';
    case Fcm  = 'fcm';

    public function label(): string
    {
        return match ($this) {
            self::Apns => 'APNs',
            self::Fcm  => 'FCM HTTP v1',
        };
    }

    /**
     * The maximum encoded payload size, in bytes.
     *
     * APNs, verbatim: "You must not use a compressed JSON payload, and it's
     * limited to a maximum size of 4 KB (4096 bytes). The maxiumum size for a
     * Voice over Internet Protocol (VoIP) notification is 5 KB (5120 bytes)."
     * (the misspelling is Apple's).
     *
     * FCM does not publish one number for the whole message, because the
     * message wraps a per-platform payload — and for the iOS leg the limit that
     * actually applies is APNs'. 4096 is therefore the honest ceiling to
     * enforce for both: it is the real one on the Apple path, and on the
     * Android path a 4 KB notification is already far past anything a device
     * will display.
     */
    public function payloadLimit(bool $voip = false): int
    {
        return match ($this) {
            self::Apns => $voip ? 5120 : 4096,
            self::Fcm  => 4096,
        };
    }
}
