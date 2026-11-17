<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class PollPixelforge extends Command
{
    protected $signature = 'pixelforge:poll';

    protected $description = 'Resync generations whose webhooks are late or missing';

    public function handle(): int
    {
        // Implemented by its slice; see specs/001-image-generation/tasks.md.
        return self::SUCCESS;
    }
}
