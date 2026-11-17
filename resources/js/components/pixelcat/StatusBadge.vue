<script setup lang="ts">
import { CircleAlert, CircleCheck, Clock, Loader2 } from '@lucide/vue';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import type { GenerationStatus } from '@/types';

const props = defineProps<{
    status: GenerationStatus;
    label: string; // from the backend, e.g. "Waiting for a free slot"
}>();

const look = computed(() => {
    switch (props.status) {
        case 'succeeded':
            return {
                icon: CircleCheck,
                spin: false,
                class: 'border-transparent bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300',
            };
        case 'failed':
            return {
                icon: CircleAlert,
                spin: false,
                class: 'border-transparent bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300',
            };
        case 'waiting':
            return {
                icon: Clock,
                spin: false,
                class: 'border-transparent bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-300',
            };
        default:
            return {
                icon: Loader2,
                spin: true,
                class: 'border-transparent bg-sky-100 text-sky-900 dark:bg-sky-900/40 dark:text-sky-300',
            };
    }
});
</script>

<template>
    <Badge :class="look.class">
        <component
            :is="look.icon"
            :class="cn(look.spin && 'animate-spin motion-reduce:animate-none')"
            aria-hidden="true"
        />
        {{ label }}
    </Badge>
</template>
