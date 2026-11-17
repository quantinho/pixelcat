<?php

namespace App\Actions\Users;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Deletes what identifies a person and keeps what the money needs. Their generations and
 * ledger entries stay, so past days still reconcile with Pixelforge (which billed them), the
 * operator report keeps its revenue, and a generation still running is charged correctly
 * when it finishes. The ledger is append-only, so the account row stays too, anonymised;
 * nobody can sign in to it again.
 */
class DeleteAccount
{
    public function __invoke(User $user): void
    {
        $imagePaths = $user->generations()->whereIn('status', self::finalStatuses())->whereNotNull('image_path')->pluck('image_path')->all();

        DB::transaction(function () use ($user) {
            $user->passkeys()->delete();

            if (config('session.driver') === 'database') {
                DB::table((string) config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
            }

            // Prompts and images are the person's content; nothing about money is in them. Only
            // finished generations are scrubbed: an unfinished one may still be (re)sent with
            // its Idempotency-Key, and a different prompt would be a different request.
            Generation::query()->where('user_id', $user->id)->whereIn('status', self::finalStatuses())->update([
                'prompt' => 'Deleted',
                'reference_images' => json_encode([]),
                'image_path' => null,
                'image_url' => null,
            ]);

            $user->forceFill([
                'name' => 'Deleted user',
                'email' => "deleted-{$user->id}@users.pixelcat.invalid",
                'password' => Str::random(64),
                'remember_token' => null,
                'email_verified_at' => null,
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'is_admin' => false,
            ])->save();
        });

        if ($imagePaths !== []) {
            Storage::disk('images')->delete($imagePaths);
        }
    }

    /** @return list<string> */
    private static function finalStatuses(): array
    {
        return [GenerationStatus::Succeeded->value, GenerationStatus::Failed->value];
    }
}
