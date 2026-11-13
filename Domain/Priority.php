<?php

declare(strict_types=1);

namespace Plugins\Push\Domain;

/**
 * The `apns-priority` header, and FCM's equivalent.
 *
 * Apple, verbatim: "The priority of the notification. If you omit this header,
 * APNs sets the notification priority to `10`. Specify `10` to send the
 * notification immediately. Specify `5` to send the notification based on power
 * considerations on the user's device. Specify 1 to prioritize the device's
 * power considerations over all other factors for delivery, and prevent
 * awakening the device."
 *
 * The default being 10 is worth knowing, because it is the WRONG default for
 * the most common silent push: a data-only notification sent at priority 10 is
 * rejected on the FCM path ("For data-only iOS messages, only normal priority
 * (`apns-priority` '5') is allowed") and is throttled by iOS on the direct
 * path. {@see PushType::requiredPriority()} is what keeps the two in step.
 */
enum Priority: int
{
    case Immediate = 10;
    case Normal    = 5;
    case Low       = 1;

    public function label(): string
    {
        return match ($this) {
            self::Immediate => 'immediate (10)',
            self::Normal    => 'power-aware (5)',
            self::Low       => 'lowest, never wakes the device (1)',
        };
    }

    /**
     * FCM's `android.priority`, which has two values rather than three.
     *
     * The mapping is lossy in one direction only: APNs' 1 and 5 both become
     * `normal`, because Android has no "never wake the device" tier. Rounding
     * the other way — treating 5 as `high` — would turn every silent sync push
     * into one that wakes the handset, which is the behaviour the sender
     * explicitly asked not to have.
     */
    public function fcmAndroidPriority(): string
    {
        return $this === self::Immediate ? 'high' : 'normal';
    }
}
