<?php

declare(strict_types=1);

namespace Plugins\Push\Infrastructure;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HttpClientPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\LoggerPort;
use Plugins\Push\Application\Services\PushService;
use Plugins\Push\Infrastructure\Auth\ApnsProviderToken;
use Plugins\Push\Infrastructure\Auth\GoogleAccessToken;
use Plugins\Push\Infrastructure\Config\PushConfig;
use Plugins\Push\Infrastructure\Gateways\ApnsGateway;
use Plugins\Push\Infrastructure\Gateways\FcmGateway;
use Plugins\Push\Infrastructure\Persistence\DeviceRepository;
use Plugins\Push\Infrastructure\Persistence\OutboxRepository;

/**
 * Assembles the service from the ports a project bound.
 *
 * A GATEWAY IS BUILT ONLY IF IT IS ENABLED, AND THAT IS LOAD-BEARING
 * ------------------------------------------------------------------
 * A disabled provider yields `null`, and {@see PushService::deliver()} turns a
 * null gateway into a rejection that names the missing configuration. The
 * alternative — building a gateway that throws on first use — produces the
 * same failure with a stack trace instead of an instruction, on a path nobody
 * is watching.
 *
 * The options are resolved ONCE and handed to everything that needs them.
 * Resolving them twice would be a quieter bug than it sounds: the config is
 * deterministic, so the two copies would agree, right up until a value derived
 * per process is added and they silently stop agreeing.
 */
final class PushFactory
{
    public static function make(
        DatabasePort $db,
        ?HttpClientPort $http = null,
        ?CachePort $cache = null,
        ?LoggerPort $logger = null,
        ?EventBus $events = null,
        ?PushConfig $config = null,
    ): PushService {
        $config ??= PushConfig::fromEnv();

        $apns = null;
        $fcm  = null;

        if ($http !== null && $config->apnsEnabled) {
            $apns = new ApnsGateway(
                $config,
                $http,
                new ApnsProviderToken($config, $cache),
            );
        }

        if ($http !== null && $config->fcmEnabled) {
            $fcm = new FcmGateway(
                $config,
                $http,
                new GoogleAccessToken($config, $http, $cache),
            );
        }

        return new PushService(
            config:  $config,
            devices: new DeviceRepository($db),
            outbox:  new OutboxRepository($db),
            apns:    $apns,
            fcm:     $fcm,
            logger:  $logger,
            events:  $events,
        );
    }

    /**
     * The warnings a status command prints — settings that look fine and are not.
     *
     * @return list<string>
     */
    public static function warnings(PushConfig $config, ?CachePort $cache, ?HttpClientPort $http): array
    {
        $warnings = [];

        if (!$config->enabled) {
            $warnings[] = 'PUSH_ENABLED is false, so every send is a silent no-op. Nothing below is '
                . 'reached at runtime.';
        }

        if (!$config->apnsEnabled && !$config->fcmEnabled) {
            $warnings[] = 'Neither PUSH_APNS_ENABLED nor PUSH_FCM_ENABLED is true, so there is no '
                . 'provider to deliver through. Every notification will be queued and then rejected.';
        }

        if ($http === null) {
            $warnings[] = 'No HttpClientPort is bound, so no notification can leave this process. The '
                . 'plugin requires the http.client domain — check that the HttpClient plugin is '
                . 'registered in withModules().';
        }

        if ($cache === null) {
            // The 20-minute rule. This is the warning that matters most on a
            // web deployment and the one nobody expects.
            $warnings[] = 'No CachePort is bound, so provider tokens are cached per PROCESS. Under '
                . 'PHP-FPM every request is a fresh process, so every request mints a new token — and '
                . 'Apple refuses that: "Update the authentication token no more than once every 20 '
                . 'minutes", answered with 429 TooManyProviderTokenUpdates. Bind a CachePort (Redis, '
                . 'APCu) before sending through APNs from web requests.';
        }

        if ($config->dryRun) {
            $warnings[] = 'PUSH_DRY_RUN is on. FCM requests are sent with validate_only (so they are '
                . 'checked and not delivered) and APNs requests are not sent at all, because APNs has '
                . 'no validate-only mode.';
        }

        if ($config->apnsEnabled && $config->apnsEnvironment === \Plugins\Push\Domain\PushEnvironment::Sandbox) {
            $warnings[] = 'PUSH_APNS_ENVIRONMENT is sandbox, which is the DEFAULT for a registration '
                . 'that does not state its own. A production build registering without an explicit '
                . 'environment would be sent to api.sandbox.push.apple.com and answered with '
                . 'BadDeviceToken — which reads as a dead token.';
        }

        return $warnings;
    }
}
