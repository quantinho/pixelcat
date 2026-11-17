<?php

namespace App\Listeners;

use App\Events\GenerationSucceeded;
use App\Jobs\StoreGenerationImage;

/**
 * Keep a copy of every generated image; Pixelforge's URL expires after 24 hours.
 */
class StoreImageOnSuccess
{
    public function handle(GenerationSucceeded $event): void
    {
        StoreGenerationImage::dispatch($event->generation->id);
    }
}
