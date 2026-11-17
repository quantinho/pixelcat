<script setup lang="ts">
type Props = {
    title: string;
    description?: string;
    variant?: 'default' | 'small';
    /** h1 when this is the page's title; h2 inside a page that already has one. */
    level?: 'h1' | 'h2';
};

withDefaults(defineProps<Props>(), {
    variant: 'default',
    level: 'h2',
});
</script>

<template>
    <header :class="variant === 'small' ? '' : 'mb-8 space-y-0.5'">
        <component
            :is="level"
            :class="
                variant === 'small'
                    ? 'mb-0.5 text-base font-medium'
                    : 'text-xl font-semibold tracking-tight'
            "
        >
            {{ title }}
        </component>
        <p v-if="description" class="text-sm text-muted-foreground">
            {{ description }}
        </p>
    </header>
</template>
