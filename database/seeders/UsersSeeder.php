<?php

namespace Database\Seeders;

use App\Billing\Ledger;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The admin and the demo user. Passwords come from ADMIN_PASSWORD and DEMO_USER_PASSWORD
 * (never from the repository); seeding stops if either is empty. Running it again keeps
 * the users and their ledger and only updates the passwords.
 */
class UsersSeeder extends Seeder
{
    public const ADMIN_EMAIL = 'admin@pixelcat.test';

    public const DEMO_EMAIL = 'demo@pixelcat.test';

    public function __construct(private readonly Ledger $ledger) {}

    public function run(): void
    {
        // Read at seed time from the environment; these are not app configuration.
        $adminPassword = $this->password('ADMIN_PASSWORD');
        $demoPassword = $this->password('DEMO_USER_PASSWORD');

        $this->user(self::ADMIN_EMAIL, 'Pixelcat Admin', $adminPassword, isAdmin: true);
        $this->user(self::DEMO_EMAIL, 'Demo User', $demoPassword, isAdmin: false);
    }

    private function password(string $variable): string
    {
        $password = Env::get($variable);

        if (! is_string($password) || $password === '') {
            throw new RuntimeException("Set {$variable} in the environment before seeding.");
        }

        return $password;
    }

    private function user(string $email, string $name, string $password, bool $isAdmin): void
    {
        DB::transaction(function () use ($email, $name, $password, $isAdmin) {
            $user = User::query()->where('email', $email)->first();

            if ($user !== null) {
                $user->forceFill(['password' => $password, 'is_admin' => $isAdmin])->save();

                return;
            }

            $user = new User(['name' => $name, 'email' => $email, 'password' => $password]);
            $user->forceFill(['email_verified_at' => now(), 'is_admin' => $isAdmin])->save();

            // Like every new user (FR-018).
            $this->ledger->grant($user, (int) config('pixelcat.welcome_credit'), 'Welcome credit');
        });
    }
}
