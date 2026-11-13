<?php

declare(strict_types=1);

namespace Plugins\Push\Domain;

/**
 * The `apns-push-type` header.
 *
 * WHY THIS IS AN ENUM AND NOT A STRING
 * ------------------------------------
 * Apple, verbatim: "The value of this header must accurately reflect the
 * contents of your notification's payload. If there's a mismatch, or if the
 * header is missing on required systems, APNs may return an error, delay the
 * delivery of the notification, or drop it altogether."
 *
 * Read that list again: *error, delay, or drop*. Two of the three are silent.
 * A header that is merely wrong — rather than malformed — buys a notification
 * that never arrives and never fails, which is the hardest class of bug in
 * this whole plugin to find. An unconstrained string invites exactly that.
 *
 * The twelve values below are the complete list from Apple's "Create a POST
 * request to APNs" table: accessory, alert, background, complication,
 * controls, fileprovider, liveactivity, location, mdm, pushtotalk, voip,
 * widgets.
 *
 * It is `Required` for watchOS 6 and later and `recommended` everywhere else —
 * so it is always sent here, because "recommended" plus "may drop it
 * altogether" is not a coin worth flipping.
 */
enum PushType: string
{
    case Alert        = 'alert';
    case Background   = 'background';
    case Location     = 'location';
    case Voip         = 'voip';
    case Complication = 'complication';
    case FileProvider = 'fileprovider';
    case Mdm          = 'mdm';
    case LiveActivity = 'liveactivity';
    case Accessory    = 'accessory';
    case Controls     = 'controls';
    case PushToTalk   = 'pushtotalk';
    case Widgets      = 'widgets';

    /**
     * The priority this push type REQUIRES, or null when it may vary.
     *
     * A `background` push must be priority 5. FCM's own Message reference says
     * the same thing from the other side: "For data-only iOS messages, only
     * normal priority (`apns-priority` '5') is allowed." Sending a background
     * push at priority 10 is a rejection on one path and a drop on the other.
     */
    public function requiredPriority(): ?Priority
    {
        return match ($this) {
            self::Background => Priority::Normal,
            default          => null,
        };
    }

    /**
     * Whether a payload of this type may carry an `alert`.
     *
     * A `background` push is defined by NOT waking the user: it must be
     * content-available only. Putting an alert in one is a contradiction APNs
     * resolves by ignoring the push type, the alert, or both.
     */
    public function allowsAlert(): bool
    {
        return match ($this) {
            self::Background, self::Mdm, self::FileProvider => false,
            default                                         => true,
        };
    }

    /** VoIP has its own, larger payload ceiling (5120 bytes). */
    public function isVoip(): bool
    {
        return $this === self::Voip;
    }

    /**
     * The `apns-topic` suffix this push type needs, or '' for the bare bundle id.
     *
     * ONLY ONE SUFFIX IS ASSERTED HERE, AND THAT IS DELIBERATE.
     *
     * Apple's current reference says exactly this much and no more: "In
     * general, the topic is your app's bundle ID/app ID. It can have a suffix
     * based on the type of push notification." It does not publish the table.
     *
     * `.push-type.liveactivity` is in Apple's own WWDC23 session "Update Live
     * Activities with push notifications", which states the `apns-topic` is
     * "your app's bundle ID, followed by .push-type.liveactivity". That is a
     * first-party source, so it is encoded.
     *
     * `.voip` is widely used and appears in Apple's ARCHIVED notification
     * guide and in developer-forum answers — but not in the current reference,
     * so this plugin does not put it in an operator's request on the strength
     * of a forum post. The same goes for the suffixes people use for
     * complications, file providers and push-to-talk: plausible, unverified,
     * and a wrong `apns-topic` is answered with `BadTopic` or
     * `DeviceTokenNotForTopic` rather than anything that explains itself.
     *
     * So for every other type the topic is whatever the operator configured.
     * Set `PUSH_APNS_TOPIC` to the FULL topic for that app, or pass one per
     * notification — {@see Notification::$topic}. A value you supplied is a
     * value you can check against Apple's documentation for your push type; a
     * value this enum invented is one you would never think to doubt.
     */
    public function topicSuffix(): string
    {
        return match ($this) {
            self::LiveActivity => '.push-type.liveactivity',
            default            => '',
        };
    }

    /**
     * Whether this type is known to need a topic suffix this plugin does not
     * assert, so a status command can say so rather than let it fail silently.
     */
    public function mayNeedTopicSuffix(): bool
    {
        return match ($this) {
            self::Voip, self::Complication, self::FileProvider, self::PushToTalk => true,
            default                                                             => false,
        };
    }
}
