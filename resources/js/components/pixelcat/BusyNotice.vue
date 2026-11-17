<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Hourglass } from '@lucide/vue';
import { computed } from 'vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';

// FR-014: shown while Pixelforge is rate-limiting us or answered 503 recently.
const page = usePage();
const busy = computed(() => page.props.pixelforge_busy);
</script>

<template>
    <div role="status" aria-live="polite">
        <Alert v-if="busy">
            <Hourglass />
            <AlertTitle>Pixelforge is busy</AlertTitle>
            <AlertDescription>
                New images may take longer to start. They wait in line, and you
                only pay for images that finish.
            </AlertDescription>
        </Alert>
    </div>
</template>
