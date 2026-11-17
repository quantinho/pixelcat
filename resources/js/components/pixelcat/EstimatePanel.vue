<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import MoneyText from '@/components/pixelcat/MoneyText.vue';
import { Spinner } from '@/components/ui/spinner';
import type { EstimateProblem } from '@/composables/useEstimate';
import type { Estimate } from '@/types';

const props = defineProps<{
    estimate: Estimate | null;
    pending: boolean;
    problem: EstimateProblem | null;
}>();

const TOKEN_LABELS: Record<string, string> = {
    input_text_tokens: 'Prompt text',
    input_image_tokens: 'Reference images',
    output_tokens: 'Image output',
    reasoning_tokens: 'Model thinking',
};

function tokenLabel(type: string): string {
    return TOKEN_LABELS[type] ?? type.replaceAll('_', ' ');
}

const ratesAge = computed(() => {
    const fetchedAt = props.estimate?.rates_fetched_at;

    if (!fetchedAt) {
        return null;
    }

    const minutes = Math.round((Date.now() - Date.parse(fetchedAt)) / 60000);

    if (minutes < 1) {
        return 'Prices checked with Pixelforge just now';
    }

    return `Prices checked with Pixelforge ${new Intl.RelativeTimeFormat('en', { numeric: 'auto' }).format(-minutes, 'minute')}`;
});
</script>

<template>
    <section
        aria-labelledby="estimate-heading"
        class="rounded-xl border bg-card p-4 text-card-foreground shadow-xs"
    >
        <div class="flex items-center justify-between gap-2">
            <h2 id="estimate-heading" class="text-sm font-medium">
                Estimated price
            </h2>
            <Spinner v-if="pending" class="text-muted-foreground" />
        </div>

        <div aria-live="polite" :aria-busy="pending">
            <template v-if="estimate">
                <p
                    class="mt-2 text-3xl font-semibold tracking-tight"
                    :class="pending && 'opacity-60'"
                >
                    <MoneyText :money="estimate.likely" />
                </p>
                <p class="text-sm text-muted-foreground">
                    Between <MoneyText :money="estimate.min" /> and
                    <MoneyText :money="estimate.max" />
                </p>
            </template>
            <p
                v-else-if="problem?.kind === 'unavailable'"
                class="mt-2 text-sm text-red-600 dark:text-red-500"
            >
                Pixelforge is unreachable right now, so there's no price and
                nothing can be started. Try again in a minute.
            </p>
            <p
                v-else-if="problem?.kind === 'throttled'"
                class="mt-2 text-sm text-muted-foreground"
            >
                Slow down a little. The price updates again in a few seconds.
            </p>
            <p
                v-else-if="problem"
                class="mt-2 text-sm text-red-600 dark:text-red-500"
            >
                We couldn't load the price right now. It will update when you
                change the form.
            </p>
            <p v-else class="mt-2 text-sm text-muted-foreground">
                Fill in the form to see the price.
            </p>
        </div>

        <p class="mt-3 text-sm text-muted-foreground">
            The final price depends on your prompt's length and how much the
            model thinks. You pay the exact price once the image is done.
        </p>

        <p class="mt-2 text-sm font-medium">Failed images are free.</p>

        <div
            v-if="estimate && !estimate.affordable && estimate.balance"
            class="mt-3 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200"
        >
            Your balance is <MoneyText :money="estimate.balance" />. You need at
            least <MoneyText :money="estimate.max" /> to start this image.
            <Link
                href="/credits"
                class="font-medium underline underline-offset-4"
                >Buy credits</Link
            >
        </div>

        <details
            v-if="estimate && estimate.breakdown.length > 0"
            class="mt-3 text-sm"
        >
            <summary
                class="cursor-pointer rounded-sm text-muted-foreground outline-none hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50"
            >
                How it's calculated
            </summary>
            <table class="mt-2 w-full text-left">
                <caption class="sr-only">
                    Price per token type
                </caption>
                <thead class="text-xs text-muted-foreground">
                    <tr>
                        <th scope="col" class="py-1 font-normal">Part</th>
                        <th scope="col" class="py-1 text-right font-normal">
                            Tokens
                        </th>
                        <th scope="col" class="py-1 text-right font-normal">
                            Pixelforge cost
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="line in estimate.breakdown"
                        :key="line.type"
                        class="border-t"
                    >
                        <th scope="row" class="py-1 font-normal">
                            {{ tokenLabel(line.type) }}
                        </th>
                        <td class="py-1 text-right tabular-nums">
                            {{ line.tokens }}
                        </td>
                        <td class="py-1 text-right tabular-nums">
                            {{ line.cost }}
                        </td>
                    </tr>
                </tbody>
            </table>
            <p class="mt-2 text-xs text-muted-foreground">
                Your price is Pixelforge's cost plus 25%.
                <template v-if="ratesAge">{{ ratesAge }}.</template>
            </p>
        </details>
        <p v-else-if="ratesAge" class="mt-3 text-xs text-muted-foreground">
            {{ ratesAge }}.
        </p>
    </section>
</template>
