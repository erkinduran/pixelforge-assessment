<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { create, index } from '@/routes/generations';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Dashboard" />

    <div class="mx-auto flex w-full max-w-2xl flex-col gap-6 p-4">
        <Heading
            title="Dashboard"
            description="Generate an image and see what it costs, or look at your earlier generations."
        />

        <div class="flex flex-wrap gap-3">
            <Button as-child>
                <Link :href="create()">Generate an image</Link>
            </Button>
            <Button variant="outline" as-child>
                <Link :href="index()">View history</Link>
            </Button>
        </div>
    </div>
</template>
