<?php

namespace App\Http\Controllers;

use App\Enums\GenerationStatus;
use App\Http\Resources\GenerationResource;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The signed-in user's generations, newest first (contracts/pages.md "generations/Index").
 */
class GenerationHistoryController extends Controller
{
    private const PER_PAGE = 20;

    /** The page polls every 3 s; a long timeline is cut to its latest events. */
    private const EVENTS_PER_GENERATION = 20;

    /** Upper bound for ids the page may ask to refresh in one poll. */
    private const MAX_REFRESH_IDS = 100;

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('generations/Index', [
            'generations' => function () use ($request, $user) {
                // The id breaks ties between generations created in the same second.
                $page = $this->withRecentEvents($user->generations())
                    ->orderByDesc('created_at')
                    ->orderByDesc('id')
                    ->cursorPaginate(self::PER_PAGE);

                return [
                    'data' => $this->toArray($request, collect($page->items())),
                    'next_cursor' => $page->nextCursor()?->encode(),
                ];
            },
            // Counts every unfinished generation, not only the ones on this page.
            'has_unfinished' => fn () => $user->generations()
                ->whereNotIn('status', [GenerationStatus::Succeeded, GenerationStatus::Failed])
                ->exists(),
            // Only on a poll that asks for it: generations on screen below the first page that
            // were unfinished last time, so they update too.
            'refreshed_generations' => Inertia::optional(fn () => $this->toArray(
                $request,
                $this->withRecentEvents($user->generations())->whereIn('id', $this->requestedIds($request))->get(),
            )),
        ]);
    }

    /**
     * @param  HasMany<Generation, User>  $query
     * @return HasMany<Generation, User>
     */
    private function withRecentEvents(HasMany $query): HasMany
    {
        return $query->with(['events' => fn ($events) => $events
            ->reorder()
            ->orderByDesc('id')
            ->limit(self::EVENTS_PER_GENERATION)]);
    }

    /**
     * @param  Collection<int, Generation>  $generations
     * @return array<int, mixed>
     */
    private function toArray(Request $request, Collection $generations): array
    {
        // Events were loaded newest first to keep the latest ones; the timeline reads oldest first.
        $generations->each(fn (Generation $generation) => $generation->setRelation(
            'events',
            $generation->events->sortBy('id')->values(),
        ));

        return GenerationResource::collection($generations)->resolve($request);
    }

    /**
     * @return array<int, string>
     */
    private function requestedIds(Request $request): array
    {
        $ids = $request->query('ids', []);

        if (! is_array($ids)) {
            return [];
        }

        // Anything that isn't a uuid would make Postgres reject the whole query.
        return collect($ids)
            ->filter(fn ($id) => is_string($id) && Str::isUuid($id))
            ->take(self::MAX_REFRESH_IDS)
            ->values()
            ->all();
    }
}
