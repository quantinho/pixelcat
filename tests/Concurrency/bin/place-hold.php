<?php

/**
 * Places one hold the way a submission does (FR-017), in its own PHP process, so two of
 * these can race on separate database connections.
 *
 * Usage: php place-hold.php {generation_id} {amount_nanos} {start_at_unix_float} {lock|no-lock}
 *
 * Prints "accepted" or "refused".
 */

use App\Billing\Ledger;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $generationId, $amount, $startAt, $mode] = $argv;
$amount = (int) $amount;

$generation = Generation::query()->findOrFail($generationId);
$user = User::query()->findOrFail($generation->user_id);
$ledger = $app->make(Ledger::class);

// Both processes start their transaction at the same moment.
DB::connection()->getPdo();
while (microtime(true) < (float) $startAt) {
    usleep(1_000);
}

$accepted = DB::transaction(function () use ($ledger, $user, $generation, $amount, $mode): bool {
    if ($mode === 'lock') {
        $ledger->lockUser($user);
    }

    $balance = $ledger->balance($user);

    // Widen the gap between the balance check and the hold, so that without the lock
    // both processes would read the same balance and both would place their hold.
    usleep(300_000);

    if ($balance < $amount) {
        return false;
    }

    $ledger->hold($generation, $amount);

    return true;
});

echo $accepted ? 'accepted' : 'refused';
