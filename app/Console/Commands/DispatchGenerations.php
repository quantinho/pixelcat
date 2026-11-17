<?php

namespace App\Console\Commands;

use App\Actions\Generations\DispatchWaitingGenerations;
use Illuminate\Console\Command;

/**
 * The hourly safety sweep for waiting generations (FR-011). Normally the dispatcher runs from
 * events; this catches anything a lost job missed.
 */
class DispatchGenerations extends Command
{
    protected $signature = 'pixelforge:dispatch';

    protected $description = 'Submit waiting generations to Pixelforge when slots are free';

    public function handle(DispatchWaitingGenerations $dispatch): int
    {
        ['dispatched' => $dispatched, 'expired' => $expired] = $dispatch();

        $this->info("Dispatched {$dispatched} generation(s); {$expired} waited too long and failed.");

        return self::SUCCESS;
    }
}
