<?php

declare(strict_types=1);

namespace Plugins\Push\Domain;

use Plugins\Push\Domain\Exceptions\PushException;

/**
 * Builds the JSON body of an APNs request.
 *
 * TWO PLACEMENT RULES THAT ARE EASY TO GET WRONG AND SILENT WHEN YOU DO
 * ---------------------------------------------------------------------
 *   1. **Custom keys are siblings of `aps`, not children of it.** A key put
 *      inside `aps` is in Apple's reserved namespace: unknown ones are ignored,
 *      so the data simply never reaches the app, and the notification still
 *      displays perfectly. Nothing fails.
 *   2. **A background push is `content-available: 1` and nothing else.** With
 *      an `alert` alongside it, the push stops being a background push —
 *      which is the mismatch Apple warns "may … drop it altogether".
 *
 * The size check happens HERE, on the encoded bytes, because that is the only
 * place the real number exists. Checking the title and body lengths instead
 * measures the wrong thing: JSON escaping of a single emoji or a quote mark can
 * add several bytes per character, so a payload that looks like 3 KB of text
 * can encode past 4096 and be refused with 413 by a provider that has already
 * taken the TLS handshake.
 */
final readonly class ApnsPayload
{
    private function __construct(public string $json, public int $bytes)
    {
    }

    /**
     * @throws PushException when the encoded payload exceeds the provider limit
     */
    public static function build(Notification $notification): self
    {
        $aps = [];

        if ($notification->hasAlert()) {
            $alert = [];

            if ($notification->title !== '') {
                $alert['title'] = $notification->title;
            }
            if ($notification->body !== '') {
                $alert['body'] = $notification->body;
            }

            $aps['alert'] = $alert;
        }

        if ($notification->badge !== null) {
            $aps['badge'] = $notification->badge;
        }

        if ($notification->sound !== '' && $notification->type->allowsAlert()) {
            $aps['sound'] = $notification->sound;
        }

        if ($notification->type === PushType::Background) {
            // The entire definition of a background push. Apple's key is
            // hyphenated; `contentAvailable` is silently ignored.
            $aps['content-available'] = 1;
        }

        if ($notification->imageUrl !== '') {
            // Without this the app's notification service extension is never
            // invoked, so the image is never downloaded and the notification
            // displays as plain text — with no error anywhere.
            $aps['mutable-content'] = 1;
        }

        $payload = ['aps' => $aps];

        // Siblings of `aps`. See rule 1 in the class docblock.
        foreach ($notification->data as $key => $value) {
            if ($key === 'aps') {
                throw PushException::contradiction(
                    'data["aps"] would overwrite the APNs reserved dictionary, which is where the alert, '
                    . 'badge and sound live. Rename the key.',
                );
            }

            $payload[$key] = $value;
        }

        if ($notification->imageUrl !== '') {
            $payload['image_url'] = $notification->imageUrl;
        }

        $json = json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw PushException::contradiction(
                'the payload could not be encoded as JSON: ' . json_last_error_msg()
                . '. The usual cause is a data[] value that is not valid UTF-8.',
            );
        }

        $bytes = \strlen($json);
        $limit = Provider::Apns->payloadLimit($notification->type->isVoip());

        if ($bytes > $limit) {
            throw PushException::payloadTooLarge($bytes, $limit, $notification->type->isVoip());
        }

        return new self($json, $bytes);
    }

    /**
     * The decoded payload, for building an FCM `apns.payload` block.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($this->json, true) ?? [];

        return $decoded;
    }
}
