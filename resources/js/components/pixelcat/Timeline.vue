<script setup lang="ts">
import { ChevronDown } from '@lucide/vue';
import { ref } from 'vue';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import type { GenerationEvent } from '@/types';

defineProps<{
    events: GenerationEvent[];
}>();

const open = ref(false);

function time(at: string | null): string {
    if (!at) {
        return '';
    }

    return new Date(at).toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    });
}
</script>

<template>
    <Collapsible v-if="events.length > 0" v-model:open="open">
        <CollapsibleTrigger
            class="inline-flex items-center gap-1 rounded-sm text-sm text-muted-foreground outline-none hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50"
        >
            <ChevronDown
                class="size-4 transition-transform motion-reduce:transition-none"
                :class="open && 'rotate-180'"
                aria-hidden="true"
            />
            {{ open ? 'Hide' : 'Show' }} timeline ({{ events.length }})
        </CollapsibleTrigger>
        <CollapsibleContent>
            <ol class="mt-2 space-y-1.5 border-l pl-4 text-sm">
                <li
                    v-for="(event, index) in events"
                    :key="index"
                    class="relative"
                >
                    <span
                        class="absolute top-1.5 -left-[1.3rem] size-2 rounded-full bg-border"
                        aria-hidden="true"
                    />
                    <time
                        v-if="event.at"
                        :datetime="event.at"
                        class="mr-2 text-muted-foreground tabular-nums"
                        >{{ time(event.at) }}</time
                    >
                    <span>{{ event.message }}</span>
                </li>
            </ol>
        </CollapsibleContent>
    </Collapsible>
</template>
