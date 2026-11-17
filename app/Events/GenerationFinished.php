<?php

namespace App\Events;

use App\Models\Generation;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A generation reached a final state (succeeded or failed); fired after the transaction commits.
 */
class GenerationFinished implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public Generation $generation) {}
}
