<script setup lang="ts">
import { Head, useForm, useHttp } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { Plus, X } from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import {
    create,
    estimate as estimateRoute,
    index,
    store,
} from '@/routes/generations';
import type { GenerationEstimate } from '@/types';

const props = defineProps<{
    options: { sizes: string[]; qualities: string[] };
    pricing_error: string | null;
    // The server's own validation limits, so the numbers live in one place.
    limits: { prompt_max: number; references_max: number };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'History', href: index() },
            { title: 'Generate', href: create() },
        ],
    },
});

const PROMPT_MAX = props.limits.prompt_max;
const REFERENCES_MAX = props.limits.references_max;
// (kept in the script: a URL written inside the template confuses vue-tsc's position mapping)
const REFERENCE_PLACEHOLDER = 'https://example.com/style.png';

const pick = (values: string[], preferred: string) =>
    values.includes(preferred) ? preferred : (values[0] ?? '');

// One id per visit to this page (a new one on every mount, so the back button never reuses an
// old one). The server uses it as the generation's id: posting this form twice (double click,
// retry) can only ever create one generation.
const submissionId = crypto.randomUUID();

const form = useForm({
    submission_id: submissionId,
    prompt: '',
    size: pick(props.options.sizes, '1024x1024'),
    quality: pick(props.options.qualities, 'medium'),
    reference_image_urls: [] as string[],
});

// Characters, as the server counts them (an emoji is one, not two).
const promptLength = computed(() => Array.from(form.prompt).length);
const promptTooLong = computed(() => promptLength.value > PROMPT_MAX);

const cleanUrls = () =>
    form.reference_image_urls
        .map((url) => url.trim())
        .filter((url) => url !== '');

function addReference() {
    if (form.reference_image_urls.length < REFERENCES_MAX) {
        form.reference_image_urls.push('');
    }
}

function removeReference(position: number) {
    form.reference_image_urls.splice(position, 1);
}

function setReference(position: number, value: string | number) {
    form.reference_image_urls[position] = String(value);
}

// --- price estimate: asked from the server, never computed in the browser ---

type EstimateState = 'idle' | 'loading' | 'ready' | 'invalid' | 'error';

const estimate = ref<GenerationEstimate | null>(null);
const estimateState = ref<EstimateState>('idle');
const estimateMessage = ref<string | null>(null);
let latestRequest = 0;

const estimateHttp = useHttp<
    {
        prompt: string;
        size: string;
        quality: string;
        reference_image_urls: string[];
    },
    { estimate: GenerationEstimate }
>({ prompt: '', size: '', quality: '', reference_image_urls: [] });

// Same fields as the real submit. URLs are trimmed here and blank rows dropped; the prompt is
// trimmed by the server, for the estimate and the submit alike.
estimateHttp.transform(() => ({
    prompt: form.prompt,
    size: form.size,
    quality: form.quality,
    reference_image_urls: cleanUrls(),
}));

function readMessage(body: string | Record<string, unknown>): string | null {
    try {
        const data = typeof body === 'string' ? JSON.parse(body) : body;

        return typeof data?.message === 'string' ? data.message : null;
    } catch {
        return null;
    }
}

function requestEstimate() {
    estimateHttp.cancel();
    // Also invalidates a response that is still on its way, so it cannot overwrite the idle state.
    const mine = ++latestRequest;

    if (
        props.pricing_error ||
        form.prompt.trim() === '' ||
        promptTooLong.value ||
        !form.size ||
        !form.quality
    ) {
        estimate.value = null;
        estimateState.value = 'idle';

        return;
    }

    estimateState.value = 'loading';

    estimateHttp
        .post(estimateRoute.url(), {
            onSuccess: (response) => {
                if (mine !== latestRequest) {
                    return;
                }

                estimate.value = response.estimate;
                estimateState.value = 'ready';
            },
            onError: (errors) => {
                if (mine !== latestRequest) {
                    return;
                }

                estimate.value = null;
                estimateState.value = 'invalid';
                estimateMessage.value =
                    Object.values(errors)[0] ??
                    'Check the form to see an estimate.';
            },
            onHttpException: (response) => {
                if (mine !== latestRequest) {
                    return;
                }

                estimate.value = null;
                estimateState.value = 'error';
                estimateMessage.value =
                    readMessage(response.data) ??
                    'The estimate is unavailable right now.';
            },
        })
        .catch(() => {
            if (mine === latestRequest && estimateState.value === 'loading') {
                estimateState.value = 'error';
                estimateMessage.value =
                    'The estimate is unavailable right now.';
            }
        });
}

const requestEstimateSoon = useDebounceFn(requestEstimate, 400);

watch(
    () => [
        form.prompt,
        form.size,
        form.quality,
        [...form.reference_image_urls],
    ],
    () => void requestEstimateSoon(),
);

onBeforeUnmount(() => estimateHttp.cancel());

// --- submit ---

const canSubmit = computed(
    () =>
        !form.processing &&
        !props.pricing_error &&
        props.options.sizes.length > 0 &&
        props.options.qualities.length > 0 &&
        form.prompt.trim() !== '' &&
        !promptTooLong.value,
);

function submit() {
    if (!canSubmit.value) {
        return;
    }

    // Dropping blank rows first keeps the error positions (reference_image_urls.0 ...) in line with the inputs.
    form.reference_image_urls = cleanUrls();
    form.post(store.url());
}

const submitError = computed(
    () => (form.errors as Record<string, string | undefined>).pricing,
);
const referenceError = (position: number) =>
    (form.errors as Record<string, string | undefined>)[
        `reference_image_urls.${position}`
    ];
const number = new Intl.NumberFormat();
const qualityLabel = (quality: string) =>
    quality.charAt(0).toUpperCase() + quality.slice(1);
</script>

<template>
    <Head title="Generate an image" />

    <div class="mx-auto flex w-full max-w-2xl flex-col gap-6 p-4">
        <Heading
            title="Generate an image"
            description="Describe the image you want. You see a price range before you submit."
        />

        <Alert v-if="pricing_error" variant="destructive" role="alert">
            <AlertTitle>Pricing unavailable</AlertTitle>
            <AlertDescription>{{ pricing_error }}</AlertDescription>
        </Alert>

        <Alert v-if="submitError" variant="destructive" role="alert">
            <AlertTitle>Could not submit</AlertTitle>
            <AlertDescription>{{ submitError }}</AlertDescription>
        </Alert>

        <form class="flex flex-col gap-6" @submit.prevent="submit">
            <div class="grid gap-2">
                <Label for="prompt">Prompt</Label>
                <Textarea
                    id="prompt"
                    v-model="form.prompt"
                    rows="5"
                    required
                    placeholder="A lighthouse on a cliff at dawn, oil painting"
                    :aria-invalid="promptTooLong || !!form.errors.prompt"
                />
                <div class="flex justify-between gap-4 text-sm">
                    <InputError :message="form.errors.prompt" />
                    <span
                        class="ml-auto tabular-nums"
                        :class="
                            promptTooLong
                                ? 'font-medium text-red-600 dark:text-red-500'
                                : 'text-muted-foreground'
                        "
                        data-test="prompt-counter"
                    >
                        {{ number.format(promptLength) }} /
                        {{ number.format(PROMPT_MAX) }}
                    </span>
                </div>
            </div>

            <div class="grid gap-6 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="size">Size</Label>
                    <Select v-model="form.size">
                        <SelectTrigger id="size" class="w-full">
                            <SelectValue placeholder="Choose a size" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="size in options.sizes"
                                :key="size"
                                :value="size"
                            >
                                {{ size }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.size" />
                </div>

                <div class="grid gap-2">
                    <Label for="quality">Quality</Label>
                    <Select v-model="form.quality">
                        <SelectTrigger id="quality" class="w-full">
                            <SelectValue placeholder="Choose a quality" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="quality in options.qualities"
                                :key="quality"
                                :value="quality"
                            >
                                {{ qualityLabel(quality) }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.quality" />
                </div>
            </div>

            <div class="grid gap-2">
                <div class="flex items-center justify-between">
                    <Label>Reference images (optional)</Label>
                    <span class="text-sm text-muted-foreground">
                        {{ form.reference_image_urls.length }} /
                        {{ REFERENCES_MAX }}
                    </span>
                </div>

                <div
                    v-for="(url, position) in form.reference_image_urls"
                    :key="position"
                    class="grid gap-1"
                >
                    <div class="flex gap-2">
                        <Input
                            type="url"
                            inputmode="url"
                            :placeholder="REFERENCE_PLACEHOLDER"
                            :aria-label="`Reference image URL ${position + 1}`"
                            :aria-invalid="!!referenceError(position)"
                            :model-value="url"
                            @update:model-value="
                                (value) => setReference(position, value)
                            "
                        />
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            :aria-label="`Remove reference image ${position + 1}`"
                            @click="removeReference(position)"
                        >
                            <X />
                        </Button>
                    </div>
                    <InputError :message="referenceError(position)" />
                </div>

                <InputError :message="form.errors.reference_image_urls" />

                <div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="
                            form.reference_image_urls.length >= REFERENCES_MAX
                        "
                        @click="addReference"
                    >
                        <Plus /> Add a reference image
                    </Button>
                </div>
                <p class="text-sm text-muted-foreground">
                    Links only (http or https). Each reference image adds to the
                    price.
                </p>
            </div>

            <section
                class="rounded-lg border p-4"
                aria-live="polite"
                data-test="estimate"
            >
                <h3 class="text-sm font-medium">Estimated price</h3>

                <p
                    v-if="estimateState === 'ready' && estimate"
                    class="mt-1 text-2xl font-semibold tabular-nums"
                >
                    {{ estimate.min_price }} &ndash; {{ estimate.max_price }}
                </p>
                <p
                    v-else-if="estimateState === 'loading'"
                    class="mt-1 flex items-center gap-2 text-muted-foreground"
                >
                    <Spinner /> Calculating&hellip;
                </p>
                <p
                    v-else-if="
                        estimateState === 'invalid' || estimateState === 'error'
                    "
                    class="mt-1 text-sm text-red-600 dark:text-red-500"
                >
                    {{ estimateMessage }}
                </p>
                <p v-else class="mt-1 text-sm text-muted-foreground">
                    Write a prompt to see the price range.
                </p>

                <details
                    v-if="estimateState === 'ready' && estimate"
                    class="mt-3 text-sm text-muted-foreground"
                >
                    <summary class="cursor-pointer">What is counted</summary>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        <li>
                            Output:
                            {{ number.format(estimate.tokens.min.output) }}
                            tokens (exact for this size and quality)
                        </li>
                        <li>
                            Reference images:
                            {{ number.format(estimate.tokens.min.image) }}
                            tokens (exact)
                        </li>
                        <li>
                            Prompt text:
                            {{ number.format(estimate.tokens.min.text) }} to
                            {{ number.format(estimate.tokens.max.text) }} tokens
                        </li>
                        <li>
                            Reasoning:
                            {{ number.format(estimate.tokens.min.reasoning) }}
                            to
                            {{ number.format(estimate.tokens.max.reasoning) }}
                            tokens
                        </li>
                    </ul>
                </details>

                <p class="mt-3 text-sm text-muted-foreground">
                    The exact number of text and reasoning tokens is only known
                    after the image is generated, so this is a range. The final
                    price is recorded when the generation succeeds. A failed
                    generation costs nothing.
                </p>
            </section>

            <div class="flex items-center gap-4">
                <Button
                    type="submit"
                    :disabled="!canSubmit"
                    data-test="submit-generation"
                >
                    <Spinner v-if="form.processing" />
                    {{ form.processing ? 'Submitting…' : 'Generate' }}
                </Button>
            </div>
        </form>
    </div>
</template>
