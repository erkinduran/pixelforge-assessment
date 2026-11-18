<script setup lang="ts">
import { CircleCheck, CircleX, Clock, LoaderCircle } from '@lucide/vue';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import type { GenerationStatus } from '@/types';

const props = defineProps<{ status: GenerationStatus }>();

// Colour is never the only signal: every status also has its own icon and word.
const look = computed(() => {
    switch (props.status) {
        case 'queued':
            return {
                label: 'Queued',
                icon: Clock,
                class: 'border-transparent bg-slate-100 text-slate-800 dark:bg-slate-800 dark:text-slate-100',
                spin: false,
            };
        case 'processing':
            return {
                label: 'Processing',
                icon: LoaderCircle,
                class: 'border-transparent bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-200',
                spin: true,
            };
        case 'succeeded':
            return {
                label: 'Succeeded',
                icon: CircleCheck,
                class: 'border-transparent bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-200',
                spin: false,
            };
        default:
            return {
                label: 'Failed',
                icon: CircleX,
                class: 'border-transparent bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200',
                spin: false,
            };
    }
});
</script>

<template>
    <Badge :class="look.class" :data-status="status">
        <component
            :is="look.icon"
            aria-hidden="true"
            :class="{ 'animate-spin': look.spin }"
        />
        {{ look.label }}
    </Badge>
</template>
