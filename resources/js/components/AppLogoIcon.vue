<script setup lang="ts">
import { Sparkles } from '@lucide/vue';
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
    <Sparkles :class="className" v-bind="$attrs" />
</template>
