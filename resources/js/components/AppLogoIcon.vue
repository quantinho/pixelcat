<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <!-- Pixelcat's mark: a cat head with its eyes cut out. -->
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 24 24"
        :class="className"
        aria-hidden="true"
        v-bind="$attrs"
    >
        <path
            fill="currentColor"
            fill-rule="evenodd"
            clip-rule="evenodd"
            d="M3 2.5 8 7h8l5-4.5V13a9 9 0 0 1-18 0V2.5ZM8.75 11.5a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3Zm6.5 0a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3ZM10.5 17h3L12 18.5 10.5 17Z"
        />
    </svg>
</template>
