<?php

namespace App\Actions\Fortify;

use App\Billing\Ledger;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(private readonly Ledger $ledger) {}

    /**
     * Validate and create a newly registered user with their welcome credit (FR-018).
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        // Each account gets free credit, so scripted sign-ups could use up the shared account.
        $throttleKey = 'signup:'.request()->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, (int) config('pixelcat.signups_per_ip_per_hour'))) {
            throw ValidationException::withMessages([
                'email' => 'Too many accounts were created from your network. Try again in an hour.',
            ]);
        }

        // A user never exists without their welcome credit.
        $user = DB::transaction(function () use ($input) {
            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);

            $this->ledger->grant($user, (int) config('pixelcat.welcome_credit'), 'Welcome credit');

            return $user;
        });

        RateLimiter::hit($throttleKey, 3600);

        return $user;
    }
}
