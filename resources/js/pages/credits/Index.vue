<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import MoneyText from '@/components/pixelcat/MoneyText.vue';
import { Button } from '@/components/ui/button';
import { index, purchase } from '@/routes/credits';
import type { CreditsPageProps } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Credits', href: index() }],
    },
});

defineProps<CreditsPageProps>();

const page = usePage();

const entryLabels: Record<string, string> = {
    grant: 'Grant',
    purchase: 'Purchase',
    hold: 'Hold',
    hold_release: 'Hold released',
    charge: 'Charge',
};

// Users see times in their browser's time zone (spec Assumptions).
function localTime(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString() : '';
}
</script>

<template>
    <Head title="Credits" />

    <div class="flex flex-1 flex-col gap-8 p-4 md:p-6">
        <Heading
            title="Credits"
            description="Each image is paid from your balance. While an image is generating, the top of its estimate is held and released when it finishes; you pay only its actual price."
        />

        <section aria-labelledby="balance-heading" class="space-y-4">
            <div class="rounded-xl border p-4">
                <h2 id="balance-heading" class="text-sm text-muted-foreground">
                    Your balance
                </h2>
                <p class="mt-1 text-3xl font-semibold">
                    <MoneyText
                        v-if="page.props.balance"
                        :money="page.props.balance"
                    />
                </p>
            </div>

            <div class="space-y-2">
                <h2 class="text-base font-medium">Buy credits</h2>
                <p class="text-sm text-muted-foreground">
                    This is a demo: no payment is taken.
                </p>
                <div class="flex flex-wrap gap-3">
                    <Form
                        v-for="pack in packs"
                        :key="pack.amount"
                        v-bind="purchase.form()"
                        :options="{ preserveScroll: true }"
                        v-slot="{ processing }"
                    >
                        <input type="hidden" name="pack" :value="pack.amount" />
                        <Button
                            type="submit"
                            variant="outline"
                            :disabled="processing"
                        >
                            Add {{ pack.label }}
                        </Button>
                    </Form>
                </div>
                <InputError :message="page.props.errors?.pack" />
            </div>
        </section>

        <section aria-labelledby="history-heading" class="space-y-3">
            <h2 id="history-heading" class="text-lg font-semibold">
                Recent activity
            </h2>

            <p
                v-if="entries.length === 0"
                class="text-sm text-muted-foreground"
            >
                No activity yet.
            </p>

            <div v-else class="overflow-x-auto rounded-xl border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th scope="col" class="px-4 py-2 font-medium">
                                When
                            </th>
                            <th scope="col" class="px-4 py-2 font-medium">
                                What
                            </th>
                            <th scope="col" class="px-4 py-2 font-medium">
                                Type
                            </th>
                            <th
                                scope="col"
                                class="px-4 py-2 text-right font-medium"
                            >
                                Amount
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(entry, i) in entries"
                            :key="i"
                            class="border-t"
                        >
                            <td class="px-4 py-2 whitespace-nowrap">
                                {{ localTime(entry.at) }}
                            </td>
                            <td class="px-4 py-2">{{ entry.description }}</td>
                            <td class="px-4 py-2 text-muted-foreground">
                                {{ entryLabels[entry.type] ?? entry.type }}
                            </td>
                            <td class="px-4 py-2 text-right">
                                <MoneyText :money="entry.amount" />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
