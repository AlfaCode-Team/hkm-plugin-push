<?php

declare(strict_types=1);

namespace Plugins\Push\Domain;

use Plugins\Push\Domain\Exceptions\PushException;

/**
 * Builds the JSON body of an FCM HTTP v1 `messages:send` request.
 *
 * The body is `{ "validate_only": bool, "message": { … } }`, and the message
 * carries ONE target from a oneof: `fid`, `token`, `topic` or `condition`. Two
 * details of that are worth stating because both are recent:
 *
 *   - **`token` is deprecated.** Firebase's Message reference says "Use `fid`
 *     instead", and accepts a Firebase Installation ID in the old field during
 *     the transition. {@see DeviceToken::fcmTarget()} picks whichever this
 *     device registered, so a half-migrated fleet works.
 *   - **`android` and `android_v2` are mutually exclusive** and "requests that
 *     include both are rejected". Only `android` is sent here.
 *
 * WHY THE APNs BLOCK IS BUILT EVEN THOUGH THIS IS THE FCM PATH
 * -----------------------------------------------------------
 * Because FCM delivers to iOS by calling APNs, and anything not in the `apns`
 * block is translated by FCM using ITS defaults — which are not this plugin's.
 * The clearest case is priority: an iOS data-only message needs
 * `apns-priority: 5`, and the only place to say so is `apns.headers`. Omit the
 * block and FCM picks, so the same Notification behaves differently depending
 * on which path it took, which is the one thing a provider-independent
 * notification must not do.
 */
final readonly class FcmMessage
{
    private function __construct(public string $json, public int $bytes)
    {
    }

    /**
     * @throws PushException when the payload cannot be built or is too large
     */
    public static function build(
        DeviceToken $device,
        Notification $notification,
        string $apnsTopic,
        bool $validateOnly = false,
        ?int $now = null,
    ): self {
        $now    ??= time();
        $target  = $device->fcmTarget();
        $message = [$target['field'] => $target['value']];

        if ($notification->hasAlert()) {
            $block = [];

            if ($notification->title !== '') {
                $block['title'] = $notification->title;
            }
            if ($notification->body !== '') {
                $block['body'] = $notification->body;
            }
            if ($notification->imageUrl !== '') {
                $block['image'] = $notification->imageUrl;
            }

            $message['notification'] = $block;
        }

        if ($notification->data !== []) {
            $message['data'] = $notification->data;
        }

        // ── Android ──────────────────────────────────────────────────────────
        $android = ['priority' => $notification->priority->fcmAndroidPriority()];

        if ($notification->ttlSeconds !== null) {
            // A protobuf Duration, which is a STRING with a trailing 's' —
            // an integer here is INVALID_ARGUMENT.
            $android['ttl'] = $notification->ttlSeconds . 's';
        }

        if ($notification->collapseId !== '') {
            $android['collapse_key'] = $notification->collapseId;
        }

        $message['android'] = $android;

        // ── APNs, via FCM ────────────────────────────────────────────────────
        $headers = [
            'apns-push-type' => $notification->type->value,
            'apns-priority'  => (string) $notification->priority->value,
        ];

        $topic = $notification->topic !== ''
            ? $notification->topic
            : $apnsTopic . $notification->type->topicSuffix();

        if ($topic !== '') {
            $headers['apns-topic'] = $topic;
        }

        $expiration = $notification->expirationAt($now);

        if ($expiration !== null) {
            $headers['apns-expiration'] = (string) $expiration;
        }

        if ($notification->collapseId !== '') {
            $headers['apns-collapse-id'] = $notification->collapseId;
        }

        $message['apns'] = [
            'headers' => $headers,
            'payload' => ApnsPayload::build($notification)->toArray(),
        ];

        $body = ['message' => $message];

        if ($validateOnly) {
            // Firebase's own dry run: "an optional flag for testing a request
            // without delivering the message". The only way to prove a whole
            // configuration works without sending anybody a notification.
            $body['validate_only'] = true;
        }

        $json = json_encode($body, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw PushException::contradiction(
                'the FCM message could not be encoded as JSON: ' . json_last_error_msg(),
            );
        }

        return new self($json, \strlen($json));
    }
}
