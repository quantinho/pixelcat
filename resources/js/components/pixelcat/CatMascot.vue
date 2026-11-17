<script setup lang="ts">
// A small line-drawn cat for empty and failure states only (never next to prices).
// Decorative: the text next to it always says what happened.
withDefaults(
    defineProps<{
        mood?: 'curious' | 'sad';
        size?: number;
    }>(),
    {
        mood: 'curious',
        size: 64,
    },
);
</script>

<template>
    <svg
        :width="size"
        :height="size"
        viewBox="0 0 64 64"
        fill="none"
        stroke="currentColor"
        stroke-width="2.5"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
        focusable="false"
        class="shrink-0"
    >
        <!-- Head and ears -->
        <path
            d="M14 26 L12 8 L25 17 Q32 15 39 17 L52 8 L50 26 Q54 34 50 42 Q44 52 32 52 Q20 52 14 42 Q10 34 14 26 Z"
        />
        <!-- Eyes -->
        <template v-if="mood === 'curious'">
            <circle cx="24" cy="32" r="2.5" fill="currentColor" stroke="none" />
            <circle cx="40" cy="32" r="2.5" fill="currentColor" stroke="none" />
        </template>
        <template v-else>
            <path d="M20 31 Q24 34 28 31" />
            <path d="M36 31 Q40 34 44 31" />
        </template>
        <!-- Nose and mouth -->
        <path d="M30.5 38 L33.5 38 L32 40 Z" fill="currentColor" />
        <path v-if="mood === 'curious'" d="M28 43 Q32 46 36 43" />
        <path v-else d="M28 45 Q32 42 36 45" />
        <!-- Whiskers -->
        <path d="M6 36 L18 38 M6 42 L18 41 M58 36 L46 38 M58 42 L46 41" />
    </svg>
</template>
