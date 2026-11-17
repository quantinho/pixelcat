<?php

namespace App\Http\Middleware;

use Closure;
use Database\Seeders\UsersSeeder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reviewers share the seeded demo login. Changing its email or password, turning on 2FA or
 * passkeys, or deleting it would lock everyone else out, so those actions are refused for
 * that one account. Everything else, including generating and buying credits, works.
 */
class ProtectSharedDemoAccount
{
    private const PROTECTED_ROUTES = [
        'profile.update',
        'profile.destroy',
        'user-password.update',
        'two-factor.enable',
        'two-factor.confirm',
        'two-factor.disable',
        'two-factor.regenerate-recovery-codes',
        'passkey.store',
        'passkey.destroy',
    ];

    public const MESSAGE = "The shared demo account can't be changed. Create your own account to try this.";

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->email !== UsersSeeder::DEMO_EMAIL || ! $request->routeIs(...self::PROTECTED_ROUTES)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => self::MESSAGE], 403);
        }

        Inertia::flash('toast', ['type' => 'error', 'message' => self::MESSAGE]);

        return back(303);
    }
}
