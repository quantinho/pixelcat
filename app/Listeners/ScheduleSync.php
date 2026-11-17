<?php

namespace App\Listeners;

use App\Events\GenerationAccepted;
use App\Jobs\SyncGeneration;

/**
 * Pixelforge has the generation now: check on it in 2 minutes in case no webhook arrives.
 */
class ScheduleSync
{
    public function handle(GenerationAccepted $event): void
    {
        SyncGeneration::schedule($event->generation->id);
    }
}
