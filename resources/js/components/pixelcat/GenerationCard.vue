<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ImageOff, RotateCcw } from '@lucide/vue';
import { computed, ref } from 'vue';
import CatMascot from '@/components/pixelcat/CatMascot.vue';
import MoneyText from '@/components/pixelcat/MoneyText.vue';
import StatusBadge from '@/components/pixelcat/StatusBadge.vue';
import Timeline from '@/components/pixelcat/Timeline.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import type { Generation, Size } from '@/types';

const props = defineProps<{
    generation: Generation;
}>();

const ASPECT: Record<Size, string> = {
    '1024x1024': 'aspect-square',
    '1536x1024': 'aspect-[3/2]',
    '1024x1536': 'aspect-[2/3]',
};

const SIZE_LABEL: Record<Size, string> = {
    '1024x1024': 'Square, 1024×1024',
    '1536x1024': 'Landscape, 1536×1024',
    '1024x1536': 'Portrait, 1024×1536',
};

const failed = computed(() => props.generation.status === 'failed');

// The image route answers 404 once no copy is left (contracts/http.md).
const imageBroken = ref(false);
const showImage = computed(
    () => props.generation.image_src !== null && !imageBroken.value,
);
const showExpired = computed(
    () =>
        props.generation.image_expired ||
        (props.generation.image_src !== null && imageBroken.value),
);

const errorMessage = computed(
    () =>
        props.generation.error?.message ??
        "Something went wrong. You weren't charged.",
);

// The backend's messages already end with "You weren't charged."; add it only if missing.
const needsNotChargedNote = computed(
    () =>
        props.generation.charged === null &&
        !/weren.t charged/i.test(errorMessage.value),
);

const referenceCount = computed(() => props.generation.reference_images.length);

const createdAt = computed(() =>
    props.generation.created_at
        ? new Date(props.generation.created_at).toLocaleString([], {
              dateStyle: 'medium',
              timeStyle: 'short',
          })
        : null,
);

const positionPercent = computed(() =>
    props.generation.estimate_position === null
        ? null
        : `${Math.round(props.generation.estimate_position * 100)}%`,
);
</script>

<template>
    <article
        class="grid gap-4 rounded-xl border bg-card p-4 text-card-foreground shadow-xs sm:grid-cols-[12rem_1fr]"
        :aria-label="`Image: ${generation.prompt}`"
    >
        <!-- Image or placeholder -->
        <div
            class="flex w-full items-center justify-center overflow-hidden rounded-lg bg-muted sm:w-48"
            :class="ASPECT[generation.size]"
        >
            <a
                v-if="showImage && generation.image_src"
                :href="generation.image_src"
                target="_blank"
                rel="noopener"
                class="block size-full rounded-lg outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
            >
                <img
                    :src="generation.image_src"
                    @error="imageBroken = true"
                    :alt="generation.prompt"
                    loading="lazy"
                    class="size-full object-cover"
                />
                <span class="sr-only">(opens full size in a new tab)</span>
            </a>
            <div
                v-else-if="showExpired"
                class="flex flex-col items-center gap-2 p-4 text-center text-sm text-muted-foreground"
            >
                <ImageOff class="size-6" aria-hidden="true" />
                Image expired
            </div>
            <div
                v-else-if="failed"
                class="flex flex-col items-center gap-2 p-4 text-center text-sm text-muted-foreground"
            >
                <CatMascot mood="sad" :size="48" />
                No image
            </div>
            <div
                v-else
                class="flex flex-col items-center gap-2 p-4 text-center text-sm text-muted-foreground"
            >
                <Spinner class="size-6" />
                {{ generation.status_label }}
            </div>
        </div>

        <div class="flex min-w-0 flex-col gap-3">
            <div class="flex flex-wrap items-center gap-2">
                <StatusBadge
                    :status="generation.status"
                    :label="generation.status_label"
                />
                <time
                    v-if="createdAt && generation.created_at"
                    :datetime="generation.created_at"
                    class="text-xs text-muted-foreground"
                    >{{ createdAt }}</time
                >
            </div>

            <p class="text-sm break-words whitespace-pre-line">
                {{ generation.prompt }}
            </p>

            <p class="text-xs text-muted-foreground">
                {{ SIZE_LABEL[generation.size] }} · {{ generation.quality }}
                quality
                <template v-if="referenceCount > 0">
                    · {{ referenceCount }} reference
                    {{ referenceCount === 1 ? 'image' : 'images' }}
                </template>
            </p>

            <!-- Prices -->
            <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                <dt class="text-muted-foreground">Estimate</dt>
                <dd>
                    <MoneyText :money="generation.estimate.min" />–<MoneyText
                        :money="generation.estimate.max"
                    />
                </dd>
                <dt class="text-muted-foreground">Price</dt>
                <dd>
                    <MoneyText
                        v-if="generation.charged"
                        :money="generation.charged"
                        class="font-medium"
                    />
                    <span v-else-if="failed">Not charged</span>
                    <span v-else class="text-muted-foreground"
                        >Charged when the image is done</span
                    >
                </dd>
            </dl>

            <div
                v-if="generation.charged && positionPercent !== null"
                class="max-w-xs"
            >
                <div
                    class="relative h-1.5 rounded-full bg-muted"
                    role="img"
                    :aria-label="`The price landed at ${positionPercent} of the estimate range`"
                >
                    <span
                        class="absolute top-1/2 size-3 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-background bg-primary"
                        :style="{ left: positionPercent }"
                    />
                </div>
                <div
                    class="mt-1 flex justify-between text-xs text-muted-foreground"
                    aria-hidden="true"
                >
                    <span>Low end</span>
                    <span>High end</span>
                </div>
            </div>

            <!-- Failure -->
            <div
                v-if="failed"
                class="flex flex-col gap-2 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-900 sm:flex-row sm:items-center sm:justify-between dark:border-red-900 dark:bg-red-950/40 dark:text-red-200"
            >
                <p>
                    {{ errorMessage }}
                    <template v-if="needsNotChargedNote">
                        You weren't charged.</template
                    >
                </p>
                <Button as-child variant="outline" size="sm">
                    <Link :href="`/generate?retry=${generation.id}`">
                        <RotateCcw aria-hidden="true" />
                        Try again
                    </Link>
                </Button>
            </div>

            <Timeline :events="generation.events" />
        </div>
    </article>
</template>
