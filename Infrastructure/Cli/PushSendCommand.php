<?php

declare(strict_types=1);

namespace Plugins\Push\Infrastructure\Cli;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use Plugins\Push\API\Contracts\PushServiceContract;
use Plugins\Push\Domain\Exceptions\PushException;
use Plugins\Push\Domain\Notification;
use Plugins\Push\Domain\PushType;

/**
 * `push:send` — send one notification to one device, now, and report what the
 * provider said.
 *
 * The only end-to-end proof that exists. `push:status` can prove the
 * credentials; only a real device token can prove the topic, the environment
 * and the payload — and only a handset in your hand can prove it arrived.
 */
final class PushSendCommand extends AbstractCommand
{
    public function __construct(private readonly ?PushServiceContract $push = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name        = 'push:send';
        $this->description = 'Send one test notification to a registered device token';

        $this->addOption('token', 't', 'The registered device token', acceptsValue: true);
        $this->addOption('title', '', 'Notification title', acceptsValue: true, default: 'Test notification');
        $this->addOption('body', 'b', 'Notification body', acceptsValue: true, default: 'Sent by push:send.');
        $this->addOption('badge', '', 'Badge count to display', acceptsValue: true);
        $this->addOption('silent', '', 'Send a data-only background push instead of an alert');
    }

    protected function handle(): int
    {
        if ($this->push === null) {
            $this->error(
                'The push service is not bound. The project has to register the Push plugin in '
                . 'withModules() and bind a DatabasePort and an HttpClientPort.',
            );

            return self::FAILURE;
        }

        $token = trim((string) $this->option('token', ''));

        if ($token === '') {
            $this->error('--token is required. It must be a token that registerDevice() has stored.');

            return self::INVALID;
        }

        $badge = $this->option('badge');

        try {
            $notification = $this->hasOption('silent')
                ? Notification::silent(['source' => 'push:send', 'at' => (string) time()])
                : Notification::of(
                    title: (string) $this->option('title', ''),
                    body:  (string) $this->option('body', ''),
                    data:  ['source' => 'push:send'],
                    type:  PushType::Alert,
                    badge: $badge !== null && is_numeric($badge) ? (int) $badge : null,
                );
        } catch (PushException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        }

        $this->section('push:send');

        $report = $this->push->sendNow($token, $notification);

        // Printed only when a provider was actually CHOSEN. For a refusal
        // raised before routing — an unregistered token — naming one is a
        // small untruth that sends somebody looking for why their iOS token
        // went to Google.
        if ($report->providerIsKnown()) {
            $this->muted('Provider : ' . $report->provider->label());
        }

        $this->muted('Outcome  : ' . $report->outcome->label());

        if ($report->reason !== '') {
            $this->muted('Reason   : ' . $report->reason);
        }

        if ($report->providerMessageId !== '') {
            // The only handle a support request to Apple or Google can use.
            $this->muted('Message id: ' . $report->providerMessageId . ' — quote this if you ask the '
                . 'provider what happened to it');
        }

        $this->newLine();

        if ($report->isDelivered()) {
            $this->success($report->detail);
            $this->muted(
                'Accepted is not seen. The provider has taken the notification; whether it is '
                . 'displayed depends on the device being reachable, the app\'s notification '
                . 'permission, and a Focus mode nobody can see from here.',
            );

            return self::SUCCESS;
        }

        if ($report->outcome->prunesToken()) {
            $this->warning($report->detail);
            $this->muted('The registration has been removed, so the app must register again.');

            return self::FAILURE;
        }

        $this->error($report->detail);

        return self::FAILURE;
    }
}
