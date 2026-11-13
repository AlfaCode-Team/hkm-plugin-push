<?php

declare(strict_types=1);

namespace Plugins\Push;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HttpClientPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\LoggerPort;
use Plugins\Push\API\Contracts\PushServiceContract;
use Plugins\Push\Application\Services\PushService;
use Plugins\Push\Infrastructure\Config\PushConfig;
use Plugins\Push\Infrastructure\Persistence\DeviceRepository;
use Plugins\Push\Infrastructure\Persistence\OutboxRepository;
use Plugins\Push\Infrastructure\PushFactory;

/**
 * Push — wires the 'push.notifications' domain.
 *
 * Device-token registration and push delivery over APNs (HTTP/2, ES256
 * provider tokens) and FCM HTTP v1 (RS256 service-account tokens), with a
 * delivery outbox and automatic pruning of tokens the provider reports dead.
 */
final class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'push.notifications';
    }

    /** @return list<string> module DOMAINS — mirrors module.json "requires" */
    public function requires(): array
    {
        return [
            'database.management',
            'logging.application',
            'http.client',
        ];
    }

    /** @return list<class-string> */
    public function exposes(): array
    {
        return [PushServiceContract::class];
    }

    public function register(ModuleContainer $container): void
    {
        // Resolved ONCE per request and shared by everything below. Reading the
        // environment twice would be cheap and still wrong: the two copies
        // would agree today and silently stop agreeing the moment a value is
        // derived per process.
        $container->bindInternal(PushConfig::class, static fn (): PushConfig => PushConfig::fromEnv());

        $container->bindInternal(
            DeviceRepository::class,
            static fn ($c): DeviceRepository => new DeviceRepository($c->make(DatabasePort::class)),
        );

        $container->bindInternal(
            OutboxRepository::class,
            static fn ($c): OutboxRepository => new OutboxRepository($c->make(DatabasePort::class)),
        );

        // The published contract. Every optional port is resolved defensively:
        // a project may bind a logger and no cache, or a database and no HTTP
        // client, and the plugin reports what is missing rather than failing to
        // construct — which would take out unrelated commands in the same
        // container.
        $container->bind(
            PushServiceContract::class,
            static fn ($c): PushService => PushFactory::make(
                db:     $c->make(DatabasePort::class),
                http:   $c->has(HttpClientPort::class) ? $c->make(HttpClientPort::class) : null,
                cache:  $c->has(CachePort::class) ? $c->make(CachePort::class) : null,
                logger: $c->has(LoggerPort::class) ? $c->make(LoggerPort::class) : null,
                events: $c->has(EventBus::class) ? $c->make(EventBus::class) : null,
                config: $c->make(PushConfig::class),
            ),
        );
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        // ── Commands MUST be registered here, not left to the manifest ──────
        //
        // module.json `commands[]` declares them, and that declaration alone is
        // not enough: `DeclaredCommands` deliberately SKIPS any command whose
        // constructor takes a parameter — optional ones included — because
        // building it from the CoreContainer would wire it to core bindings
        // rather than the ones a module chose. All three of these inject
        // dependencies, so relying on the manifest left them silently
        // non-existent: `push:status` answered "Command not found".
        //
        // Deferred so this only runs on the CLI path. Every entry point
        // constructs a CliPipeline, the HTTP one included, so building three
        // commands and their repositories at boot would be work an HTTP
        // request pays for and never uses.
        $cli->defer(static function (CliPipeline $cli): void {
            $core = $cli->container();

            $db = $core->has(DatabasePort::class) ? $core->make(DatabasePort::class) : null;

            $devices = $db instanceof DatabasePort ? new DeviceRepository($db) : null;
            $outbox  = $db instanceof DatabasePort ? new OutboxRepository($db) : null;

            $http  = $core->has(HttpClientPort::class) ? $core->make(HttpClientPort::class) : null;
            $cache = $core->has(CachePort::class) ? $core->make(CachePort::class) : null;

            $cli->command(new Infrastructure\Cli\PushStatusCommand(
                devices: $devices,
                outbox:  $outbox,
                http:    $http instanceof HttpClientPort ? $http : null,
                cache:   $cache instanceof CachePort ? $cache : null,
            ));

            // The two commands that SEND need the whole service. A deployment
            // with no DatabasePort gets them anyway, reporting that the
            // service is unbound — which is more useful than the command
            // being absent, because "command not found" reads as a bad
            // install rather than a missing port.
            $service = null;

            if ($db instanceof DatabasePort) {
                try {
                    $service = PushFactory::make(
                        db:     $db,
                        http:   $http instanceof HttpClientPort ? $http : null,
                        cache:  $cache instanceof CachePort ? $cache : null,
                        logger: $core->has(LoggerPort::class) && $core->make(LoggerPort::class) instanceof LoggerPort
                            ? $core->make(LoggerPort::class)
                            : null,
                        events: $core->has(EventBus::class) && $core->make(EventBus::class) instanceof EventBus
                            ? $core->make(EventBus::class)
                            : null,
                    );
                } catch (\Throwable) {
                    // A misconfigured credential must not take the whole CLI
                    // down — `push:status` is the command that diagnoses it,
                    // and it is registered above without needing the service.
                    $service = null;
                }
            }

            $cli->command(new Infrastructure\Cli\PushSendCommand($service));
            $cli->command(new Infrastructure\Cli\PushDrainCommand($service));
        });

        // No routes: a device-registration endpoint belongs to the PROJECT,
        // because only the project knows how its clients authenticate and
        // which user a registration may be attributed to. A route shipped here
        // would either be unauthenticated — letting anyone register a token
        // against any user id, which is the delivery-to-the-wrong-person bug
        // by a different route — or would hard-code an auth scheme this plugin
        // has no business assuming.
    }
}
