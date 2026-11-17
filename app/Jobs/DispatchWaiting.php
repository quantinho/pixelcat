<?php

namespace App\Jobs;

use App\Actions\Generations\DispatchWaitingGenerations;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs the slot dispatcher. Queued when a generation is created, when one finishes and after
 * a concurrency 429 (delayed by Retry-After). Not unique: concurrent runs are serialized by
 * the dispatcher's advisory lock, and a second run simply finds nothing to do.
 */
class DispatchWaiting implements ShouldQueue
{
    use Queueable;

    public function handle(DispatchWaitingGenerations $dispatch): void
    {
        $dispatch();
    }
}
