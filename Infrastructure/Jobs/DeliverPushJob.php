<?php

declare(strict_types=1);

namespace Plugins\Push\Infrastructure\Jobs;

use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\Contracts\JobContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\JobPayload;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\JobResult;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\LoggerPort;
use Plugins\Push\API\Contracts\PushServiceContract;

/**
 * Drains the outbox.
 *
 * WHY module.json DECLARES `retry.max = 1`
 * ----------------------------------------
 * Because the OUTBOX owns retries, and it is the only thing that can. Each row
 * tracks its own attempts and its own next-available time, so a row that failed
 * transiently is retried on its own schedule by the next drain.
 *
 * A job-level retry would re-run the whole BATCH, which means re-sending to
 * every device whose row was already marked delivered in the first pass. There
 * is no way to take a notification back, so a duplicate is permanent — and the
 * batch succeeding "except for one row" is the normal case, not the exception.
 *
 * So this job never throws for a delivery failure. It throws only when it
 * cannot run at all, which is the one case a retry can fix.
 */
final class DeliverPushJob implements JobContract
{
    public function __construct(
        private readonly ?PushServiceContract $push = null,
        private readonly ?LoggerPort $logger = null,
    ) {
    }

    public function handle(JobPayload $payload): JobResult
    {
        if ($this->push === null) {
            // Skipped, not failed: retrying cannot bind a service that the
            // project did not wire, and a job that fails forever buries the
            // real problem under its own noise.
            return JobResult::skipped(
                'The push service is not bound, so there is nothing to drain. The project has to '
                . 'register the Push plugin in withModules() for this job to do anything.',
            );
        }

        $limit = $payload->data()['limit'] ?? 0;
        $tally = $this->push->drain(\is_numeric($limit) ? (int) $limit : 0);

        if ($tally['queued'] === 0) {
            return JobResult::skipped('Nothing was due.');
        }

        // One line per BATCH, not per notification. A broadcast to ten
        // thousand devices would otherwise produce ten thousand log lines and
        // bury everything else in the file.
        $this->logger?->info('push.drain', $tally);

        return JobResult::success($tally);
    }

    public function failed(JobPayload $payload, \Throwable $e): void
    {
        $this->logger?->error('push.drain.failed', [
            'error' => $e->getMessage(),
            'class' => $e::class,
        ]);
    }
}
