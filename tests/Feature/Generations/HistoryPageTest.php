<?php

use App\Models\Generation;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('sends guests to the login page', function () {
    auth()->logout();

    $this->get('/generations')->assertRedirect(route('login'));
});

it('renders the history page with the props from contracts/pages.md', function () {
    $generation = Generation::factory()->for($this->user)->succeeded()->create();
    $generation->recordEvent('submitted', 'Sent to Pixelforge');

    $this->actingAs($this->user)
        ->get('/generations')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('generations/Index')
            ->has('generations.data', 1, fn (Assert $item) => $item
                ->where('id', $generation->id)
                ->where('status', 'succeeded')
                ->where('status_label', 'Done')
                ->where('is_final', true)
                ->has('estimate.min.display')
                ->has('estimate.likely.exact')
                ->has('estimate.max.display')
                ->has('charged.display')
                ->has('charged.exact')
                ->has('events', 1)
                ->where('events.0.message', 'Sent to Pixelforge')
                ->etc())
            ->where('generations.next_cursor', null)
            ->where('has_unfinished', false)
            ->has('balance.display')
            ->has('pixelforge_busy'));
});

it('lists generations newest first', function () {
    $old = Generation::factory()->for($this->user)->succeeded()->create(['created_at' => now()->subHours(2)]);
    $new = Generation::factory()->for($this->user)->failed()->create(['created_at' => now()->subMinute()]);
    $middle = Generation::factory()->for($this->user)->succeeded()->create(['created_at' => now()->subHour()]);

    $this->actingAs($this->user)
        ->get('/generations')
        ->assertInertia(fn (Assert $page) => $page
            ->where('generations.data.0.id', $new->id)
            ->where('generations.data.1.id', $middle->id)
            ->where('generations.data.2.id', $old->id));
});

it('pages through history with a cursor, 20 at a time', function () {
    foreach (range(1, 25) as $minutesAgo) {
        Generation::factory()->for($this->user)->succeeded()->create(['created_at' => now()->subMinutes($minutesAgo)]);
    }

    $firstPage = null;

    $this->actingAs($this->user)
        ->get('/generations')
        ->assertInertia(function (Assert $page) use (&$firstPage) {
            $page->has('generations.data', 20)->whereType('generations.next_cursor', 'string');
            $firstPage = $page->toArray()['props']['generations'];
        });

    $secondPage = null;

    $this->get('/generations?cursor='.$firstPage['next_cursor'])
        ->assertInertia(function (Assert $page) use (&$secondPage) {
            $page->has('generations.data', 5)->where('generations.next_cursor', null);
            $secondPage = $page->toArray()['props']['generations'];
        });

    $ids = collect($firstPage['data'])->concat($secondPage['data'])->pluck('id');
    $expected = Generation::query()->orderByDesc('created_at')->orderByDesc('id')->pluck('id');

    expect($ids->all())->toBe($expected->all());
});

it('keeps generations created in the same second in a stable order across pages', function () {
    $sameMoment = now()->subMinute()->startOfSecond();
    Generation::factory()->for($this->user)->count(21)->succeeded()->create(['created_at' => $sameMoment]);

    $firstPage = null;

    $this->actingAs($this->user)
        ->get('/generations')
        ->assertInertia(function (Assert $page) use (&$firstPage) {
            $firstPage = $page->toArray()['props']['generations'];
        });

    $this->get('/generations?cursor='.$firstPage['next_cursor'])
        ->assertInertia(fn (Assert $page) => $page
            ->has('generations.data', 1)
            ->where('generations.data.0.id', fn (string $id) => ! in_array($id, array_column($firstPage['data'], 'id'), true)));
});

it('reports unfinished generations so the page keeps polling', function (string $state, bool $unfinished) {
    Generation::factory()->for($this->user)->succeeded()->create();
    Generation::factory()->for($this->user)->{$state}()->create();

    $this->actingAs($this->user)
        ->get('/generations')
        ->assertInertia(fn (Assert $page) => $page->where('has_unfinished', $unfinished));
})->with([
    'waiting' => ['waiting', true],
    'submitting' => ['submitting', true],
    'queued' => ['queued', true],
    'processing' => ['processing', true],
    'succeeded' => ['succeeded', false],
    'failed' => ['failed', false],
]);

it('counts an unfinished generation beyond the first page', function () {
    Generation::factory()->for($this->user)->queued()->create(['created_at' => now()->subDay()]);
    Generation::factory()->for($this->user)->count(20)->succeeded()->create();

    $this->actingAs($this->user)
        ->get('/generations')
        ->assertInertia(fn (Assert $page) => $page
            ->has('generations.data', 20)
            ->where('has_unfinished', true));
});

it('shows only the signed-in user\'s generations', function () {
    $mine = Generation::factory()->for($this->user)->succeeded()->create();
    Generation::factory()->for(User::factory())->queued()->create();

    $this->actingAs($this->user)
        ->get('/generations')
        ->assertInertia(fn (Assert $page) => $page
            ->has('generations.data', 1)
            ->where('generations.data.0.id', $mine->id)
            ->where('has_unfinished', false));
});

it('shows an empty history', function () {
    $this->actingAs($this->user)
        ->get('/generations')
        ->assertInertia(fn (Assert $page) => $page
            ->component('generations/Index')
            ->has('generations.data', 0)
            ->where('generations.next_cursor', null)
            ->where('has_unfinished', false));
});

it('reloads only the polled props', function () {
    Generation::factory()->for($this->user)->processing()->create();

    $this->actingAs($this->user)
        ->get('/generations')
        ->assertInertia(fn (Assert $page) => $page
            ->reloadOnly(['generations', 'has_unfinished', 'balance', 'pixelforge_busy'], fn (Assert $reload) => $reload
                ->has('generations.data', 1)
                ->where('has_unfinished', true)
                ->has('balance')
                ->has('pixelforge_busy')
                ->missing('auth')));
});
