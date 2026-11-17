<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

it('answers the admin gate for a user that was not read back from the database', function () {
    $user = User::factory()->create();

    expect(Gate::forUser($user)->allows('admin'))->toBeFalse()
        ->and(Gate::forUser(User::factory()->admin()->create())->allows('admin'))->toBeTrue();
});
