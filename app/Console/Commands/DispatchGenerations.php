<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class DispatchGenerations extends Command
{
    protected $signature = 'pixelforge:dispatch';

    protected $description = 'Submit waiting generations to Pixelforge when slots are free';

    public function handle(): int
    {
        // Implemented by its slice; see specs/001-image-generation/tasks.md.
        return self::SUCCESS;
    }
}
