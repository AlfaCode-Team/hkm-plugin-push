<?php

declare(strict_types=1);

namespace Plugins\Push\Domain;

use Plugins\Push\Domain\Exceptions\PushException;

/**
 * What to send — described once, independently of either provider.
 *
 * WHY THIS IS NOT JUST AN ARRAY
 * -----------------------------
 * Because three of its combinations are contradictions that no provider
 * reports as one. A background push carrying an alert, a background push at
 * priority 10, and a notification with neither a title nor a body are all
 * accepted by the wire format and then dropped, delayed or silently reshaped
 * by the device. {@see self::of()} refuses them at the call site, where the
 * caller still knows what they meant.
 *
 * Everything optional is absent rather than defaulted, so "the sender said
 * nothing" stays distinguishable from "the sender asked for the default" —
 * which matters for `apns-expiration`, where 0 and "omitted" mean genuinely
 * different things (one attempt and no storage, versus APNs' own storage
 * policy).
 */
final readonly class Notification
{
    /**
     * @param array<string, string> $data custom key/value pairs. FCM requires
     *        string values; APNs allows any JSON but the shared shape is
     *        strings, so that is what this takes.
     */
    private function __construct(
        public string $title,
        public string $body,
        public array $data,
        public PushType $type,
        public Priority $priority,
        /** Unread count to display. null = leave it alone. 0 = clear it. */
        public ?int $badge,
        /** Sound file name, or 'default'. '' = silent. */
        public string $sound,
        /** `apns-collapse-id` / FCM `collapse_key`. Max 64 bytes. */
        public string $collapseId,
        /** Seconds from now until the notification is pointless. null = provider default. */
        public ?int $ttlSeconds,
        /** Overrides the configured `apns-topic` for this one notification. */
        public string $topic,
        /** A URL for the image FCM and APNs can both show. */
        public string $imageUrl,
    ) {
    }

    /**
     * @param array<string, string> $data
     * @throws PushException on a combination no provider can honour
     */
    public static function of(
        string $title = '',
        string $body = '',
        array $data = [],
        PushType $type = PushType::Alert,
        ?Priority $priority = null,
        ?int $badge = null,
        string $sound = 'default',
        string $collapseId = '',
        ?int $ttlSeconds = null,
        string $topic = '',
        string $imageUrl = '',
    ): self {
        $title = trim($title);
        $body  = trim($body);

        // A push type that MUST have a given priority gets it, rather than
        // being refused for a default the caller never chose. An EXPLICIT
        // contradiction is still refused below.
        $required = $type->requiredPriority();
        $resolved = $priority ?? $required ?? Priority::Immediate;

        if ($required !== null && $priority !== null && $priority !== $required) {
            throw PushException::contradiction(\sprintf(
                'an "%s" push must be sent at priority %d, and this asks for %d. FCM states the rule from '
                . 'its side too: "For data-only iOS messages, only normal priority (apns-priority \'5\') is '
                . 'allowed." At priority %d it is rejected on the FCM path and throttled by iOS on the '
                . 'direct one.',
                $type->value,
                $required->value,
                $priority->value,
                $priority->value,
            ));
        }

        if (!$type->allowsAlert() && ($title !== '' || $body !== '')) {
            throw PushException::contradiction(\sprintf(
                'an "%s" push is defined by NOT waking the user, and this one carries a title or body. '
                . 'Apple: the apns-push-type "must accurately reflect the contents of your notification\'s '
                . 'payload. If there\'s a mismatch … APNs may return an error, delay the delivery of the '
                . 'notification, or drop it altogether." Two of those three are silent. Send an "alert" '
                . 'push, or move the text into data[].',
                $type->value,
            ));
        }

        if ($type->allowsAlert() && $title === '' && $body === '' && $data === []) {
            throw PushException::contradiction(
                'it has no title, no body and no data, so there is nothing for the device to show or act '
                . 'on. APNs answers an empty payload with 400 PayloadEmpty.',
            );
        }

        if ($collapseId !== '' && \strlen($collapseId) > 64) {
            throw PushException::contradiction(\sprintf(
                'the collapse id is %d bytes and Apple caps it at 64 ("The value of this key must not '
                . 'exceed 64 bytes"), which comes back as 400 BadCollapseId.',
                \strlen($collapseId),
            ));
        }

        if ($badge !== null && $badge < 0) {
            throw PushException::contradiction(
                'a badge count cannot be negative. Use 0 to clear the badge, or null to leave it as it is.',
            );
        }

        foreach ($data as $key => $value) {
            if (!\is_string($key) || $key === '') {
                throw PushException::contradiction('every data[] key must be a non-empty string.');
            }

            // FCM: "Reserved keys include 'from', 'message_type', and keys
            // prefixed with 'google.' or 'gcm.notification.'." A reserved key
            // is rejected by FCM, so it is caught here with the key named.
            if (self::isReservedDataKey($key)) {
                throw PushException::contradiction(\sprintf(
                    'data["%s"] uses a key FCM reserves. Its Message reference lists "from", '
                    . '"message_type", and anything prefixed "google." or "gcm.notification.".',
                    $key,
                ));
            }

            if (!\is_string($value)) {
                throw PushException::contradiction(\sprintf(
                    'data["%s"] is %s, and FCM\'s data map is "a UTF-8 string key/value map" — a number '
                    . 'there comes back as INVALID_ARGUMENT naming the field. Cast it before sending.',
                    $key,
                    get_debug_type($value),
                ));
            }
        }

        if ($ttlSeconds !== null && $ttlSeconds < 0) {
            throw PushException::contradiction('a time to live cannot be negative.');
        }

        return new self(
            title:      $title,
            body:       $body,
            data:       $data,
            type:       $type,
            priority:   $resolved,
            badge:      $badge,
            sound:      trim($sound),
            collapseId: $collapseId,
            ttlSeconds: $ttlSeconds,
            topic:      trim($topic),
            imageUrl:   trim($imageUrl),
        );
    }

    /**
     * A data-only push, correct by construction.
     *
     * @param array<string, string> $data
     */
    public static function silent(array $data): self
    {
        return self::of(data: $data, type: PushType::Background);
    }

    public function hasAlert(): bool
    {
        return $this->title !== '' || $this->body !== '';
    }

    /**
     * `apns-expiration`: an absolute UNIX time, or null to omit the header.
     *
     * Absolute rather than relative because that is what Apple's header is —
     * "This value is a UNIX epoch expressed in seconds (UTC)" — and the
     * conversion has to happen as late as possible: a notification that waits
     * in the outbox for ten minutes must still expire when the sender meant,
     * not ten minutes later.
     */
    public function expirationAt(int $now): ?int
    {
        return $this->ttlSeconds === null ? null : $now + $this->ttlSeconds;
    }

    private static function isReservedDataKey(string $key): bool
    {
        return $key === 'from'
            || $key === 'message_type'
            || str_starts_with($key, 'google.')
            || str_starts_with($key, 'gcm.notification.');
    }
}
