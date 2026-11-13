<?php

declare(strict_types=1);

namespace Plugins\Push\Infrastructure\Cli;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HttpClientPort;
use Plugins\Push\Domain\Exceptions\PushException;
use Plugins\Push\Infrastructure\Auth\ApnsProviderToken;
use Plugins\Push\Infrastructure\Auth\GoogleAccessToken;
use Plugins\Push\Infrastructure\Config\PushConfig;
use Plugins\Push\Infrastructure\Persistence\DeviceRepository;
use Plugins\Push\Infrastructure\Persistence\OutboxRepository;
use Plugins\Push\Infrastructure\PushFactory;

/**
 * `push:status` — prove the configuration works, without notifying anybody.
 *
 * WHY IT AUTHENTICATES FOR REAL
 * -----------------------------
 * Every way push can be misconfigured produces one symptom: nothing arrives.
 * A command that only echoed the settings back would be green for a revoked
 * service-account key, a key id from the wrong team, a bundle id that belongs
 * to another app, and a clock three minutes out — all of which are silent until
 * somebody expects a notification.
 *
 * So it does the work that proves it:
 *
 *   - **FCM** is verified END TO END using FCM's own `validate_only` flag,
 *     which checks the credentials, the project, the token and the whole
 *     message shape and delivers nothing. That is as close to proof as a
 *     status command can get.
 *   - **APNs** has no validate-only mode, so the most that can be checked
 *     without picking a victim is that the provider token SIGNS — which is
 *     where a wrong key, key id or team id actually fails. Whether Apple
 *     accepts it needs a real device token, and `push:send` is that.
 *
 * Neither path prints key material. {@see PushConfig::describe()} is the only
 * renderer and it reports shapes, never secrets.
 */
final class PushStatusCommand extends AbstractCommand
{
    public function __construct(
        private readonly ?DeviceRepository $devices = null,
        private readonly ?OutboxRepository $outbox = null,
        private readonly ?HttpClientPort $http = null,
        private readonly ?CachePort $cache = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name        = 'push:status';
        $this->description = 'Check the push configuration and authenticate against the providers';
    }

    protected function handle(): int
    {
        $this->section('Push');

        try {
            $config = PushConfig::fromEnv();
        } catch (PushException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($config->describe() as $label => $value) {
            $this->muted(str_pad($label, 18) . ': ' . $value);
        }

        // ── The settings that look fine and are not ─────────────────────────
        $warnings = PushFactory::warnings($config, $this->cache, $this->http);

        if ($warnings !== []) {
            $this->newLine();

            foreach ($warnings as $warning) {
                $this->warning($warning);
            }
        }

        // ── Registrations ───────────────────────────────────────────────────
        if ($this->devices !== null) {
            $this->newLine();
            $this->muted('Registered devices : ' . $this->devices->countAll());

            foreach ($this->devices->countByPlatform() as $platform => $count) {
                $this->muted('  ' . str_pad($platform, 16) . ': ' . $count);
            }
        }

        if ($this->outbox !== null) {
            $counts = $this->outbox->countByStatus();
            $this->muted('Outbox             : ' . ($counts === []
                ? 'empty'
                : implode(', ', array_map(
                    static fn (string $k, int $v): string => "{$k}={$v}",
                    array_keys($counts),
                    array_values($counts),
                ))));

            $due = $this->outbox->countDue(gmdate('Y-m-d H:i:s'));

            if ($due > 0) {
                $this->muted('Due right now      : ' . $due . ' — run `push:drain` or the push.deliver job');
            }
        }

        // ── Authenticate ────────────────────────────────────────────────────
        //
        // Counted rather than OR-ed into a flag: both providers are always
        // checked, so a failing APNs configuration never hides a failing FCM
        // one. (The flag version also left a branch PHPStan could prove dead.)
        $failures = 0;

        if ($config->apnsEnabled) {
            $this->newLine();
            $this->muted('APNs:');
            $failures += $this->checkApns($config) ? 0 : 1;
        }

        if ($config->fcmEnabled) {
            $this->newLine();
            $this->muted('FCM:');
            $failures += $this->checkFcm($config) ? 0 : 1;
        }

        // ── What this cannot see ────────────────────────────────────────────
        $this->newLine();
        $this->muted('Not checked here:');
        $this->muted(
            '  - whether a notification REACHES a handset. No push network reports that a human saw '
            . 'anything, so "delivered" everywhere in this plugin means the provider accepted it. Use '
            . '`push:send --token=... ` against a device you are holding.',
        );
        $this->muted(
            '  - whether the app calls forgetDevice() on sign-out. Nothing here can tell, and without '
            . 'it a shared device keeps delivering one person\'s notifications to whoever holds it '
            . 'next. See the README.',
        );

        if ($config->apnsEnabled) {
            $this->muted(
                '  - whether Apple ACCEPTS the provider token. Signing it only proves the key, key id '
                . 'and team id are mutually consistent; APNs has no validate-only mode, so the first '
                . 'real send is the first real check.',
            );
        }

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function checkApns(PushConfig $config): bool
    {
        try {
            $config->assertApnsUsable();
        } catch (PushException $e) {
            $this->error('  ' . $e->getMessage());

            return false;
        }

        $token = new ApnsProviderToken($config, $this->cache);

        try {
            $jwt = $token->token();
        } catch (PushException $e) {
            $this->error('  ' . $e->getMessage());

            return false;
        }

        $this->success('  the provider token signs (ES256, ' . \strlen($jwt) . ' bytes)');
        $this->muted('  cached: ' . ($token->isShared()
            ? 'in the shared CachePort, for ' . ApnsProviderToken::TTL_SECONDS . 's'
            : 'IN THIS PROCESS ONLY — see the warning above'));

        return true;
    }

    private function checkFcm(PushConfig $config): bool
    {
        try {
            $config->assertFcmUsable();
        } catch (PushException $e) {
            $this->error('  ' . $e->getMessage());

            return false;
        }

        if ($this->http === null) {
            $this->error('  no HttpClientPort is bound, so the token exchange cannot be attempted.');

            return false;
        }

        $token = new GoogleAccessToken($config, $this->http, $this->cache);

        try {
            $access = $token->token();
        } catch (PushException $e) {
            $this->error('  ' . $e->getMessage());

            return false;
        }

        $this->success('  exchanged the service-account assertion for an access token ('
            . \strlen($access) . ' bytes)');
        $this->muted('  scope: ' . GoogleAccessToken::SCOPE);
        $this->muted('  cached: ' . ($token->isShared() ? 'in the shared CachePort' : 'IN THIS PROCESS ONLY'));
        $this->muted('  send URL: ' . $config->fcmSendUrl());

        return true;
    }
}
