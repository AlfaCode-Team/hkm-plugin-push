<?php

declare(strict_types=1);

namespace Plugins\Push\API\IntegrationEvents;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\IntegrationEventContract;

/**
 * A device token was reported dead by its provider and has been removed.
 *
 * WHY THIS IS PUBLISHED AND THE OTHER OUTCOMES ARE NOT
 * ----------------------------------------------------
 * A delivery succeeding or failing is this plugin's business. A registration
 * DISAPPEARING is not: a profile that shows "push notifications: on", a
 * reminder feature that assumed it could reach somebody, a nudge scheduled for
 * tomorrow — all of those are now wrong, and none of them can know unless they
 * are told.
 *
 * Note what it does NOT carry: the token. A token is an address and personal
 * data, and an integration event crosses module boundaries and is likely to be
 * logged or forwarded. A listener that needed the token would be a listener
 * reaching into this plugin's business; what a listener legitimately needs is
 * "this user has one fewer way to be reached", and that is what it gets.
 *
 * Primitives only, per the kernel's integration-event rule — other modules do
 * not have this plugin's value objects.
 */
final readonly class PushTokenInvalidatedIntegrationEvent implements IntegrationEventContract
{
    public string $version;

    public function __construct(
        public string $userId,
        public string $platform,
        public string $provider,
        /** The provider's reason code, verbatim: 'Unregistered', 'UNREGISTERED', … */
        public string $reason,
        public string $occurredAt,
    ) {
        $this->version = '1.0';
    }

    public function name(): string
    {
        return 'push.token_invalidated';
    }

    public function version(): string
    {
        return $this->version;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'userId'     => $this->userId,
            'platform'   => $this->platform,
            'provider'   => $this->provider,
            'reason'     => $this->reason,
            'occurredAt' => $this->occurredAt,
            'version'    => $this->version,
        ];
    }
}
