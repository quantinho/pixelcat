<script setup lang="ts">
import type { Money } from '@/types';

defineProps<{
    money: Money;
}>();
</script>

<template>
    <span class="tabular-nums" :title="'$' + money.exact">{{
        money.display
    }}</span>
</template>
