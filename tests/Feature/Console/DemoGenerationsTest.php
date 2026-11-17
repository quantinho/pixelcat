<?php

use App\Billing\Ledger;
use App\Console\Commands\DemoGenerations;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakePixelforge;

beforeEach(function () {
    FakePixelforge::fake();
    Queue::fake();
});

it('submits every demo prompt through the normal flow', function () {
    $user = User::factory()->create(['email' => 'demo@pixelcat.test']);
    app(Ledger::class)->grant($user, 2_000_000_000, 'Welcome credit');

    $this->artisan('pixelcat:demo-generations')->assertSuccessful();

    $generations = Generation::query()->where('user_id', $user->id)->get();

    expect($generations)->toHaveCount(count(DemoGenerations::PROMPTS))
        ->and($generations->every(fn (Generation $g) => $g->status === GenerationStatus::Waiting))->toBeTrue()
        // Each one holds its estimate's maximum, exactly as a submission from the form does.
        ->and(app(Ledger::class)->balance($user))->toBe(2_000_000_000 - (int) $generations->sum('estimate_max'));
});

it('skips prompts the user cannot afford instead of failing', function () {
    User::factory()->create(['email' => 'demo@pixelcat.test']);

    $this->artisan('pixelcat:demo-generations')
        ->expectsOutputToContain('Skipped')
        ->assertSuccessful();

    expect(Generation::query()->count())->toBe(0);
});

it('refuses an unknown user', function () {
    $this->artisan('pixelcat:demo-generations', ['--user' => 'nobody@pixelcat.test'])
        ->expectsOutputToContain('No user with the email')
        ->assertFailed();
});
