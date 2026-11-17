<?php

namespace App\Events;

use App\Models\Generation;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A generation reached succeeded; fired after the transaction commits.
 */
class GenerationSucceeded implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public Generation $generation) {}
}
