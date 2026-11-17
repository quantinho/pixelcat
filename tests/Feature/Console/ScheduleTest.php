<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

/**
 * The hourly safety-net sweeps and the daily reconciliation (routes/console.php). Each runs
 * on one server and never overlaps itself.
 */
function scheduledCommand(string $command): Event
{
    $events = collect(app(Schedule::class)->events())
        ->filter(fn (Event $event) => str_ends_with((string) $event->command, " {$command}")
            || str_ends_with((string) $event->command, " '{$command}'"));

    expect($events)->toHaveCount(1);

    return $events->sole();
}

it('runs each command on its schedule', function (string $command, string $expression) {
    expect(scheduledCommand($command)->expression)->toBe($expression);
})->with([
    'dispatch hourly' => ['pixelforge:dispatch', '0 * * * *'],
    'poll hourly at :30' => ['pixelforge:poll', '30 * * * *'],
    'reconcile daily at 00:15' => ['pixelforge:reconcile', '15 0 * * *'],
]);

it('reconciles at 00:15 UTC, after the UTC day has ended', function () {
    expect(scheduledCommand('pixelforge:reconcile')->timezone)->toBe('UTC');
});

it('runs each command on one server, without overlapping', function (string $command) {
    $event = scheduledCommand($command);

    expect($event->onOneServer)->toBeTrue()
        ->and($event->withoutOverlapping)->toBeTrue();
})->with(['pixelforge:dispatch', 'pixelforge:poll', 'pixelforge:reconcile']);
