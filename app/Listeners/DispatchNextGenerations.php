<?php

namespace App\Listeners;

use App\Events\GenerationFinished;
use App\Jobs\DispatchWaiting;

/**
 * A finished generation frees a Pixelforge slot: give it to the next waiting one (FR-011).
 */
class DispatchNextGenerations
{
    public function handle(GenerationFinished $event): void
    {
        DispatchWaiting::dispatch();
    }
}
