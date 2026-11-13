<?php

declare(strict_types=1);

namespace Plugins\Push\Infrastructure\Cli;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use Plugins\Push\API\Contracts\PushServiceContract;

/**
 * `push:drain` — deliver what is queued.
 *
 * The same work the `push.deliver` job does, reachable without a worker. A
 * deployment with no queue configured still has a working outbox, because the
 * outbox is a table rather than a queue — and this is how it gets drained, from
 * cron if nothing else.
 */
final class PushDrainCommand extends AbstractCommand
{
    public function __construct(private readonly ?PushServiceContract $push = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name        = 'push:drain';
        $this->description = 'Deliver queued push notifications';

        $this->addOption('limit', 'l', 'How many to attempt; 0 uses PUSH_OUTBOX_BATCH', acceptsValue: true);
    }

    protected function handle(): int
    {
        if ($this->push === null) {
            $this->error('The push service is not bound, so there is nothing to drain.');

            return self::FAILURE;
        }

        $limit = $this->option('limit');
        $tally = $this->push->drain(is_numeric($limit) ? (int) $limit : 0);

        $this->section('push:drain');

        if ($tally['queued'] === 0) {
            $this->muted('Nothing was due.');

            return self::SUCCESS;
        }

        $this->muted('Attempted : ' . $tally['queued']);
        $this->muted('Delivered : ' . $tally['delivered']);
        $this->muted('Retrying  : ' . $tally['retried']);
        $this->muted('Pruned    : ' . $tally['pruned'] . ' (the provider reported the token dead)');
        $this->muted('Rejected  : ' . $tally['rejected']);

        if ($tally['rejected'] > 0) {
            $this->newLine();
            $this->warning(
                'A rejection is a fault no retry fixes — a wrong topic, an oversized payload, bad '
                . 'credentials. The reason is on each push_outbox row in `last_reason` and '
                . '`last_error`, and in the application log at warning level.',
            );
        }

        return self::SUCCESS;
    }
}
