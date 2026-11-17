<script setup lang="ts">
import { Head, Link, router, usePoll } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { onMounted, ref, watch } from 'vue';
import BusyNotice from '@/components/pixelcat/BusyNotice.vue';
import CatMascot from '@/components/pixelcat/CatMascot.vue';
import GenerationCard from '@/components/pixelcat/GenerationCard.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import type { CursorPage, Generation } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'History', href: '/generations' }],
    },
});

const props = defineProps<{
    generations: CursorPage<Generation>;
    has_unfinished: boolean;
}>();

// The list on screen: the newest page, refreshed by polling, plus older pages loaded with
// "Load more". Unfinished generations on those older pages are refreshed by id.
const items = ref<Generation[]>([...props.generations.data]);
const nextCursor = ref<string | null>(props.generations.next_cursor);
const loadingMore = ref(false);
let newestIds = new Set(props.generations.data.map((item) => item.id));

// Same cap as GenerationHistoryController::MAX_REFRESH_IDS.
const MAX_REFRESH_IDS = 100;

function replaceKnown(fresh: Generation[]): void {
    const byId = new Map(fresh.map((item) => [item.id, item]));

    items.value = items.value.map((item) => byId.get(item.id) ?? item);
}

function mergeNewest(page: CursorPage<Generation>): void {
    const known = new Set(items.value.map((item) => item.id));
    const added = page.data.filter((item) => !known.has(item.id));

    replaceKnown(page.data);
    items.value = [...added, ...items.value];
    newestIds = new Set(page.data.map((item) => item.id));
}

/** Unfinished generations on screen that the newest page doesn't cover. */
function olderUnfinishedIds(): string[] {
    return items.value
        .filter((item) => !item.is_final && !newestIds.has(item.id))
        .map((item) => item.id)
        .slice(0, MAX_REFRESH_IDS);
}

function appendOlder(page: CursorPage<Generation>): void {
    const known = new Set(items.value.map((item) => item.id));

    items.value = [
        ...items.value,
        ...page.data.filter((item) => !known.has(item.id)),
    ];
    nextCursor.value = page.next_cursor;
}

const POLLED_PROPS = [
    'generations',
    'has_unfinished',
    'balance',
    'pixelforge_busy',
];

const poll = usePoll(
    3000,
    () => {
        const ids = olderUnfinishedIds();

        return {
            only:
                ids.length > 0
                    ? [...POLLED_PROPS, 'refreshed_generations']
                    : POLLED_PROPS,
            data: ids.length > 0 ? { ids } : {},
            preserveUrl: true,
            onSuccess: (page) => {
                mergeNewest(page.props.generations as CursorPage<Generation>);

                if (ids.length > 0) {
                    replaceKnown(
                        page.props.refreshed_generations as Generation[],
                    );
                }
            },
        };
    },
    { autoStart: false },
);

function syncPolling(): void {
    if (props.has_unfinished && !loadingMore.value) {
        poll.start();
    } else {
        poll.stop();
    }
}

onMounted(syncPolling);
watch(() => props.has_unfinished, syncPolling);

function loadMore(): void {
    if (!nextCursor.value || loadingMore.value) {
        return;
    }

    // Pause polling so a poll answer (the newest page) can't be mistaken for this one.
    loadingMore.value = true;
    poll.stop();

    router.reload({
        only: ['generations'],
        data: { cursor: nextCursor.value },
        preserveUrl: true,
        onSuccess: (page) =>
            appendOlder(page.props.generations as CursorPage<Generation>),
        onFinish: () => {
            loadingMore.value = false;
            syncPolling();
        },
    });
}
</script>

<template>
    <Head title="History" />

    <div class="mx-auto flex w-full max-w-4xl flex-col gap-4 p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">History</h1>
                <p class="text-sm text-muted-foreground">
                    Your images, newest first.
                    <span v-if="has_unfinished" role="status"
                        >Status updates live.</span
                    >
                </p>
            </div>
            <Button as-child>
                <Link href="/generate">
                    <Plus aria-hidden="true" />
                    New image
                </Link>
            </Button>
        </div>

        <BusyNotice />

        <div
            v-if="items.length === 0"
            class="flex flex-col items-center gap-3 rounded-xl border border-dashed p-10 text-center"
        >
            <CatMascot class="text-muted-foreground" />
            <h2 class="font-medium">No images yet</h2>
            <p class="max-w-sm text-sm text-muted-foreground">
                Each image you generate shows up here with its status, price and
                a timeline of what happened.
            </p>
            <Button as-child variant="outline">
                <Link href="/generate">Generate your first image</Link>
            </Button>
        </div>

        <template v-else>
            <ul class="flex flex-col gap-4">
                <li v-for="generation in items" :key="generation.id">
                    <GenerationCard :generation="generation" />
                </li>
            </ul>

            <div v-if="nextCursor" class="flex justify-center">
                <Button
                    variant="outline"
                    :disabled="loadingMore"
                    @click="loadMore"
                >
                    <Spinner v-if="loadingMore" />
                    Load more
                </Button>
            </div>
        </template>
    </div>
</template>
