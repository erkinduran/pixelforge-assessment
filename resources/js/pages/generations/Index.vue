<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ImageOff, Plus } from '@lucide/vue';
import { ref, watch } from 'vue';
import GenerationStatusBadge from '@/components/GenerationStatusBadge.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useGenerationPolling } from '@/composables/useGenerationPolling';
import { create, index } from '@/routes/generations';
import { isFinalStatus } from '@/types';
import type { Generation, PaginatedGenerations } from '@/types';

const props = defineProps<{ generations: PaginatedGenerations }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'History', href: index() }],
    },
});

// A local copy, so the polling can update rows in place; a new page from the server replaces it.
const rows = ref<Generation[]>(props.generations.data);
watch(
    () => props.generations.data,
    (data) => {
        rows.value = data;
    },
);

const { failing } = useGenerationPolling(rows);

// Times come from ISO strings and are shown in the viewer's own locale.
const formatTime = (iso: string | null) =>
    iso === null ? '' : new Date(iso).toLocaleString();
</script>

<template>
    <Head title="History" />

    <div class="mx-auto flex w-full max-w-4xl flex-col gap-6 p-4">
        <div class="flex items-start justify-between gap-4">
            <Heading
                title="History"
                description="Your generations, newest first. Rows update by themselves while they run."
            />
            <Button as-child>
                <Link :href="create()"><Plus /> Generate</Link>
            </Button>
        </div>

        <p
            v-if="failing"
            class="text-sm text-amber-700 dark:text-amber-400"
            role="status"
        >
            Live updates are interrupted. Retrying&hellip;
        </p>

        <div
            v-if="rows.length === 0"
            class="flex flex-col items-center gap-3 rounded-lg border border-dashed p-10 text-center"
        >
            <p class="font-medium">No generations yet</p>
            <p class="text-sm text-muted-foreground">
                Describe an image and see its price range before you submit.
            </p>
            <Button as-child>
                <Link :href="create()">Generate your first image</Link>
            </Button>
        </div>

        <ul v-else class="flex flex-col gap-3">
            <li
                v-for="generation in rows"
                :key="generation.id"
                class="flex gap-4 rounded-lg border p-4"
                :data-status="generation.status"
            >
                <div class="shrink-0">
                    <a
                        v-if="generation.image_url"
                        :href="generation.image_url"
                        target="_blank"
                        rel="noopener noreferrer"
                        :aria-label="`Open the image for: ${generation.prompt}`"
                    >
                        <img
                            :src="generation.image_url"
                            :alt="`Generated image for: ${generation.prompt}`"
                            loading="lazy"
                            class="size-24 rounded-md border object-cover"
                        />
                    </a>
                    <div
                        v-else-if="generation.image_expired"
                        class="flex size-24 flex-col items-center justify-center gap-1 rounded-md border border-dashed p-2 text-center text-xs text-muted-foreground"
                    >
                        <ImageOff class="size-5" aria-hidden="true" />
                        Image link expired
                    </div>
                    <div
                        v-else-if="!isFinalStatus(generation.status)"
                        class="flex size-24 flex-col items-center justify-center gap-1 rounded-md border border-dashed p-2 text-center text-xs text-muted-foreground"
                    >
                        <Spinner />
                        Generating&hellip;
                    </div>
                    <div
                        v-else
                        class="size-24 rounded-md border border-dashed"
                        aria-hidden="true"
                    />
                </div>

                <div class="flex min-w-0 flex-1 flex-col gap-2">
                    <div class="flex flex-wrap items-center gap-2">
                        <GenerationStatusBadge :status="generation.status" />
                        <span class="text-sm text-muted-foreground">
                            {{ generation.size }} &middot;
                            {{ generation.quality }}
                        </span>
                        <time
                            v-if="generation.created_at"
                            :datetime="generation.created_at"
                            class="text-sm text-muted-foreground"
                        >
                            {{ formatTime(generation.created_at) }}
                        </time>
                    </div>

                    <p class="line-clamp-2 text-sm" :title="generation.prompt">
                        {{ generation.prompt }}
                    </p>

                    <dl class="flex flex-wrap gap-x-6 gap-y-1 text-sm">
                        <div class="flex gap-1">
                            <dt class="text-muted-foreground">Estimate</dt>
                            <dd class="tabular-nums">
                                {{ generation.estimate.min }} &ndash;
                                {{ generation.estimate.max }}
                            </dd>
                        </div>
                        <div v-if="generation.price" class="flex gap-1">
                            <dt class="text-muted-foreground">Final price</dt>
                            <dd class="font-medium tabular-nums">
                                {{ generation.price }}
                            </dd>
                        </div>
                    </dl>

                    <p
                        v-if="generation.failure_message"
                        class="text-sm text-red-700 dark:text-red-400"
                    >
                        {{ generation.failure_message }}
                        <span
                            v-if="
                                !/not charged/i.test(generation.failure_message)
                            "
                            class="text-muted-foreground"
                        >
                            You were not charged.
                        </span>
                    </p>
                </div>
            </li>
        </ul>

        <nav
            v-if="generations.meta.last_page > 1"
            class="flex items-center justify-between"
            aria-label="Pagination"
        >
            <Button v-if="generations.links.prev" variant="outline" as-child>
                <Link :href="generations.links.prev" preserve-scroll>
                    Newer
                </Link>
            </Button>
            <span v-else />
            <span class="text-sm text-muted-foreground">
                Page {{ generations.meta.current_page }} of
                {{ generations.meta.last_page }}
            </span>
            <Button v-if="generations.links.next" variant="outline" as-child>
                <Link :href="generations.links.next" preserve-scroll>
                    Older
                </Link>
            </Button>
            <span v-else />
        </nav>
    </div>
</template>
