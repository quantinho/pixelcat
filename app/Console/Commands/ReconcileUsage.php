<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ReconcileUsage extends Command
{
    protected $signature = 'pixelforge:reconcile {--days=7 : Completed UTC days to check}';

    protected $description = 'Compare provider costs with Pixelforge usage per UTC day';

    public function handle(): int
    {
        // Implemented by its slice; see specs/001-image-generation/tasks.md.
        return self::SUCCESS;
    }
}
