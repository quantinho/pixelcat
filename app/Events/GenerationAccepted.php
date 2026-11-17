<?php

namespace App\Events;

use App\Models\Generation;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Pixelforge accepted a generation (it moved to queued or processing); fired after the transaction commits.
 */
class GenerationAccepted implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public Generation $generation) {}
}
