<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ChevronDown, ChevronRight } from '@lucide/vue';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import MoneyText from '@/components/pixelcat/MoneyText.vue';
import { Badge } from '@/components/ui/badge';
import { deliveries, report } from '@/routes/admin';
import type { ReconciliationStatus, ReportPageProps } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Operator report', href: report() }],
    },
});

defineProps<ReportPageProps>();

const statusLabels: Record<ReconciliationStatus, string> = {
    match: 'Matches Pixelforge',
    mismatch: 'Mismatch',
    in_progress: 'In progress',
    not_checked: 'Not checked',
};

const statusVariants: Record<
    ReconciliationStatus,
    'default' | 'secondary' | 'destructive' | 'outline'
> = {
    match: 'default',
    mismatch: 'destructive',
    in_progress: 'secondary',
    not_checked: 'outline',
};

const expanded = ref<Set<string>>(new Set());

function toggle(date: string): void {
    const next = new Set(expanded.value);

    if (next.has(date)) {
        next.delete(date);
    } else {
        next.add(date);
    }

    expanded.value = next;
}

// Operators read times in UTC (spec Assumptions).
function utc(iso: string): string {
    return new Date(iso).toISOString().slice(0, 16).replace('T', ' ') + ' UTC';
}
</script>

<template>
    <Head title="Operator report" />

    <div class="flex flex-1 flex-col gap-8 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Operator report"
                description="Credit spend, provider cost and profit per UTC day, reconciled with Pixelforge usage. The last 30 days."
            />
            <Link
                :href="deliveries()"
                class="text-sm font-medium underline underline-offset-4"
            >
                Webhook deliveries
            </Link>
        </div>

        <dl class="grid gap-4 sm:grid-cols-3">
            <div class="rounded-xl border p-4">
                <dt class="text-sm text-muted-foreground">Charged to users</dt>
                <dd class="mt-1 text-2xl font-semibold">
                    <MoneyText :money="totals.charged" />
                </dd>
            </div>
            <div class="rounded-xl border p-4">
                <dt class="text-sm text-muted-foreground">Provider cost</dt>
                <dd class="mt-1 text-2xl font-semibold">
                    <MoneyText :money="totals.provider_cost" />
                </dd>
            </div>
            <div class="rounded-xl border p-4">
                <dt class="text-sm text-muted-foreground">Profit</dt>
                <dd class="mt-1 text-2xl font-semibold">
                    <MoneyText :money="totals.profit" />
                </dd>
            </div>
        </dl>

        <section aria-labelledby="days-heading" class="space-y-3">
            <h2 id="days-heading" class="text-lg font-semibold">By day</h2>

            <p v-if="days.length === 0" class="text-sm text-muted-foreground">
                Nothing billed in the last 30 days.
            </p>

            <div v-else class="overflow-x-auto rounded-xl border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th scope="col" class="px-4 py-2 font-medium">
                                Date (UTC)
                            </th>
                            <th
                                scope="col"
                                class="px-4 py-2 text-right font-medium"
                            >
                                Generations
                            </th>
                            <th
                                scope="col"
                                class="px-4 py-2 text-right font-medium"
                            >
                                Charged
                            </th>
                            <th
                                scope="col"
                                class="px-4 py-2 text-right font-medium"
                            >
                                Provider cost
                            </th>
                            <th
                                scope="col"
                                class="px-4 py-2 text-right font-medium"
                            >
                                Profit
                            </th>
                            <th scope="col" class="px-4 py-2 font-medium">
                                Reconciliation
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="day in days" :key="day.date">
                            <tr class="border-t">
                                <td class="px-4 py-2 whitespace-nowrap">
                                    <button
                                        v-if="day.users.length > 0"
                                        type="button"
                                        class="inline-flex items-center gap-1 font-medium hover:underline"
                                        :aria-expanded="expanded.has(day.date)"
                                        @click="toggle(day.date)"
                                    >
                                        <ChevronDown
                                            v-if="expanded.has(day.date)"
                                            class="size-4"
                                        />
                                        <ChevronRight v-else class="size-4" />
                                        {{ day.date }}
                                    </button>
                                    <span v-else class="pl-5 font-medium">{{
                                        day.date
                                    }}</span>
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums">
                                    {{ day.generations }}
                                </td>
                                <td class="px-4 py-2 text-right">
                                    <MoneyText :money="day.charged" />
                                </td>
                                <td class="px-4 py-2 text-right">
                                    <MoneyText :money="day.provider_cost" />
                                </td>
                                <td class="px-4 py-2 text-right">
                                    <MoneyText :money="day.profit" />
                                </td>
                                <td class="px-4 py-2">
                                    <div
                                        class="flex flex-wrap items-center gap-2"
                                    >
                                        <Badge
                                            :variant="
                                                statusVariants[
                                                    day.reconciliation.status
                                                ]
                                            "
                                        >
                                            {{
                                                statusLabels[
                                                    day.reconciliation.status
                                                ]
                                            }}
                                        </Badge>
                                        <span
                                            v-if="
                                                day.reconciliation
                                                    .pixelforge_cost
                                            "
                                            class="text-xs text-muted-foreground"
                                        >
                                            Pixelforge
                                            <MoneyText
                                                :money="
                                                    day.reconciliation
                                                        .pixelforge_cost
                                                "
                                            />
                                        </span>
                                        <span
                                            v-if="
                                                day.reconciliation.status ===
                                                    'mismatch' &&
                                                day.reconciliation.difference
                                            "
                                            class="text-xs font-medium text-destructive"
                                        >
                                            Difference
                                            <MoneyText
                                                :money="
                                                    day.reconciliation
                                                        .difference
                                                "
                                            />
                                        </span>
                                        <span
                                            v-if="day.reconciliation.checked_at"
                                            class="text-xs text-muted-foreground"
                                        >
                                            checked
                                            {{
                                                utc(
                                                    day.reconciliation
                                                        .checked_at,
                                                )
                                            }}
                                        </span>
                                    </div>
                                </td>
                            </tr>
                            <template v-if="expanded.has(day.date)">
                                <tr
                                    v-for="row in day.users"
                                    :key="day.date + row.user"
                                    class="bg-muted/20 text-muted-foreground"
                                >
                                    <td class="py-1.5 pr-4 pl-10">
                                        {{ row.user }}
                                    </td>
                                    <td
                                        class="px-4 py-1.5 text-right tabular-nums"
                                    >
                                        {{ row.generations }}
                                    </td>
                                    <td class="px-4 py-1.5 text-right">
                                        <MoneyText :money="row.charged" />
                                    </td>
                                    <td class="px-4 py-1.5 text-right">
                                        <MoneyText :money="row.provider_cost" />
                                    </td>
                                    <td class="px-4 py-1.5 text-right">
                                        <MoneyText :money="row.profit" />
                                    </td>
                                    <td />
                                </tr>
                            </template>
                        </template>
                    </tbody>
                </table>
            </div>
        </section>

        <section aria-labelledby="anomalies-heading" class="space-y-3">
            <h2 id="anomalies-heading" class="text-lg font-semibold">
                Billing anomalies
            </h2>
            <p class="text-sm text-muted-foreground">
                Generations Pixelforge completed and billed after we had marked
                them failed. The user was not charged; the cost is ours.
            </p>

            <p
                v-if="anomalies.length === 0"
                class="text-sm text-muted-foreground"
            >
                No anomalies.
            </p>

            <div v-else class="overflow-x-auto rounded-xl border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th scope="col" class="px-4 py-2 font-medium">
                                Completed (UTC)
                            </th>
                            <th scope="col" class="px-4 py-2 font-medium">
                                User
                            </th>
                            <th scope="col" class="px-4 py-2 font-medium">
                                Generation
                            </th>
                            <th
                                scope="col"
                                class="px-4 py-2 text-right font-medium"
                            >
                                Provider cost
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="anomaly in anomalies"
                            :key="anomaly.generation_id"
                            class="border-t"
                        >
                            <td class="px-4 py-2 whitespace-nowrap">
                                {{ utc(anomaly.at) }}
                            </td>
                            <td class="px-4 py-2">{{ anomaly.user }}</td>
                            <td class="px-4 py-2 font-mono text-xs">
                                {{ anomaly.generation_id }}
                            </td>
                            <td class="px-4 py-2 text-right">
                                <MoneyText :money="anomaly.provider_cost" />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
