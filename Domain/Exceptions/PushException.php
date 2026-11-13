<?php

declare(strict_types=1);

namespace Plugins\Push\Domain\Exceptions;

/**
 * Everything this plugin refuses to do, with the reason.
 *
 * A `RuntimeException` rather than a kernel `GatewayException`, because most of
 * these are raised in the Domain layer — which may import nothing outside
 * Domain/. The gateways translate the ones that cross their boundary.
 */
final class PushException extends \RuntimeException
{
    public static function invalidToken(string $why): self
    {
        return new self('This device token cannot be registered: ' . $why);
    }

    public static function payloadTooLarge(int $actual, int $limit, bool $voip): self
    {
        return new self(\sprintf(
            'The notification payload is %d bytes and the limit is %d. '
            . 'Apple is explicit that there is no way around it: "You must not use a compressed JSON '
            . 'payload, and it\'s limited to a maximum size of %s". '
            . 'Sending it anyway earns a 413 PayloadTooLarge, so it is refused here, where the message '
            . 'names the field you can shorten. The usual culprit is a data[] map carrying a whole '
            . 'record where an id would do — the device can fetch the rest.',
            $actual,
            $limit,
            $voip ? '5 KB (5120 bytes)' : '4 KB (4096 bytes)',
        ));
    }

    public static function misconfigured(string $key, string $why): self
    {
        return new self(\sprintf('Push is misconfigured: %s %s', $key, $why));
    }

    public static function signingFailed(string $why): self
    {
        return new self('Could not sign the provider token: ' . $why);
    }

    public static function noProvider(string $why): self
    {
        return new self('This notification cannot be delivered: ' . $why);
    }

    public static function contradiction(string $why): self
    {
        return new self('This notification contradicts itself: ' . $why);
    }
}
