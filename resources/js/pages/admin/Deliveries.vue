<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { deliveries as deliveriesRoute, report } from '@/routes/admin';
import type { DeliveriesPageProps } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Operator report', href: report() },
            { title: 'Webhook deliveries', href: deliveriesRoute() },
        ],
    },
});

defineProps<DeliveriesPageProps>();

// Outcomes that need an operator's attention stand out.
function variant(outcome: string): 'secondary' | 'destructive' | 'outline' {
    if (outcome === 'error' || outcome === 'rejected') {
        return 'destructive';
    }

    return outcome === 'applied' ? 'secondary' : 'outline';
}

function label(outcome: string): string {
    return outcome.replace(/_/g, ' ');
}

// Operators read times in UTC (spec Assumptions).
function utc(iso: string): string {
    return new Date(iso).toISOString().slice(0, 19).replace('T', ' ');
}
</script>

<template>
    <Head title="Webhook deliveries" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            title="Webhook deliveries"
            description="Every POST Pixelforge sent to the webhook, newest first, and what we did with it."
        />

        <p
            v-if="deliveries.data.length === 0"
            class="text-sm text-muted-foreground"
        >
            No deliveries yet.
        </p>

        <div v-else class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th scope="col" class="px-4 py-2 font-medium">
                            Received (UTC)
                        </th>
                        <th scope="col" class="px-4 py-2 font-medium">Event</th>
                        <th scope="col" class="px-4 py-2 font-medium">Type</th>
                        <th
                            scope="col"
                            class="px-4 py-2 text-right font-medium"
                        >
                            Attempt
                        </th>
                        <th scope="col" class="px-4 py-2 font-medium">
                            Outcome
                        </th>
                        <th scope="col" class="px-4 py-2 font-medium">
                            Generation
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(delivery, index) in deliveries.data"
                        :key="index + delivery.received_at"
                        class="border-t"
                    >
                        <td class="px-4 py-2 whitespace-nowrap tabular-nums">
                            {{ utc(delivery.received_at) }}
                        </td>
                        <td class="px-4 py-2 font-mono text-xs">
                            {{ delivery.event_id ?? '—' }}
                        </td>
                        <td class="px-4 py-2">
                            {{ delivery.event_type ?? '—' }}
                        </td>
                        <td class="px-4 py-2 text-right tabular-nums">
                            {{ delivery.attempt ?? '—' }}
                        </td>
                        <td class="px-4 py-2">
                            <Badge :variant="variant(delivery.outcome)">
                                {{ label(delivery.outcome) }}
                            </Badge>
                        </td>
                        <td class="px-4 py-2 font-mono text-xs">
                            {{ delivery.generation_id ?? '—' }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="flex gap-4">
            <Link
                v-if="deliveries.next_cursor"
                :href="
                    deliveriesRoute({
                        query: { cursor: deliveries.next_cursor },
                    })
                "
                class="text-sm font-medium underline underline-offset-4"
            >
                Older deliveries
            </Link>
            <Link
                v-if="$page.url.includes('cursor=')"
                :href="deliveriesRoute()"
                class="text-sm font-medium underline underline-offset-4"
            >
                Newest
            </Link>
        </div>
    </div>
</template>
