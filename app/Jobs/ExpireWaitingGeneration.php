<?php

namespace App\Jobs;

use App\Actions\Generations\DispatchWaitingGenerations;
use App\Models\Generation;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Jobs\SyncJob;

/**
 * Fails a generation that is still waiting for a slot at the waiting limit (FR-006), with its
 * hold released. Queued when the generation is created, delayed until the limit.
 *
 * Queue delays are capped at 15 minutes (the SQS limit), so a job that comes due early
 * re-dispatches itself with the remaining delay instead of acting.
 */
class ExpireWaitingGeneration implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    private const MAX_DELAY_SECONDS = 15 * 60;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Generation $generation) {}

    public static function dispatchFor(Generation $generation): void
    {
        self::dispatch($generation)->delay(self::secondsUntilDue($generation));
    }

    public function uniqueId(): string
    {
        return $this->generation->id;
    }

    public function handle(DispatchWaitingGenerations $dispatcher): void
    {
        $remaining = self::secondsUntilDue($this->generation);

        if ($remaining > 0) {
            // The sync queue ignores delays; re-dispatching there would loop. The hourly
            // sweep (pixelforge:dispatch) fails the generation instead.
            if (! $this->job instanceof SyncJob) {
                self::dispatch($this->generation)->delay($remaining);
            }

            return;
        }

        $dispatcher->failIfWaitedTooLong($this->generation);
    }

    /**
     * Seconds until the waiting limit, capped at the longest delay the queue allows.
     */
    private static function secondsUntilDue(Generation $generation): int
    {
        $deadline = DispatchWaitingGenerations::waitingDeadline($generation);

        return min(self::MAX_DELAY_SECONDS, max(0, $deadline->getTimestamp() - now()->getTimestamp()));
    }
}
