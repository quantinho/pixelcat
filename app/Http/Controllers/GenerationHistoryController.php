<?php

namespace App\Http\Controllers;

use App\Enums\GenerationStatus;
use App\Http\Resources\GenerationResource;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The signed-in user's generations, newest first (contracts/pages.md "generations/Index").
 */
class GenerationHistoryController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('generations/Index', [
            'generations' => function () use ($request, $user) {
                // The id breaks ties between generations created in the same second.
                $page = $user->generations()
                    ->with('events')
                    ->orderByDesc('created_at')
                    ->orderByDesc('id')
                    ->cursorPaginate(self::PER_PAGE);

                return [
                    'data' => GenerationResource::collection($page->items())->resolve($request),
                    'next_cursor' => $page->nextCursor()?->encode(),
                ];
            },
            // Counts every unfinished generation, not only the ones on this page.
            'has_unfinished' => fn () => $user->generations()
                ->whereNotIn('status', [GenerationStatus::Succeeded, GenerationStatus::Failed])
                ->exists(),
        ]);
    }
}
