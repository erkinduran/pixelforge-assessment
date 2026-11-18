import { useHttp } from '@inertiajs/vue3';
import { onBeforeUnmount, ref, watch } from 'vue';
import type { Ref } from 'vue';
import { pending, show } from '@/routes/generations';
import { isFinalStatus } from '@/types';
import type { Generation } from '@/types';

const INTERVAL_MS = 3000;

/**
 * Keeps the rows that are not final up to date, without reloading the page.
 * Each tick asks for the user's pending generations and replaces the matching rows in place.
 * A row that is no longer pending has just finished, so it is fetched once on its own.
 * Polling starts when a non-final row appears and stops by itself when every row is final.
 */
export function useGenerationPolling(rows: Ref<Generation[]>) {
    const http = useHttp<Record<string, never>, { data: Generation[] }>({});
    const showHttp = useHttp<Record<string, never>, { data: Generation }>({});
    const failing = ref(false);

    let timer: ReturnType<typeof setTimeout> | null = null;
    let running = false;
    let disposed = false;

    const hasActiveRows = () =>
        rows.value.some((row) => !isFinalStatus(row.status));

    async function tick() {
        timer = null;
        running = true;

        try {
            const pendingNow = await http.get(pending.url());
            const fresh = new Map(pendingNow.data.map((g) => [g.id, g]));

            // One request at a time: both hooks keep their own state.
            for (const row of rows.value.filter(
                (r) => !isFinalStatus(r.status),
            )) {
                if (!fresh.has(row.id)) {
                    fresh.set(
                        row.id,
                        (await showHttp.get(show.url(row.id))).data,
                    );
                }
            }

            rows.value = rows.value.map((row) => fresh.get(row.id) ?? row);
            failing.value = false;
        } catch {
            // Offline or a server hiccup: keep the rows we have and try again.
            failing.value = true;
        } finally {
            running = false;
        }

        schedule();
    }

    function schedule() {
        if (disposed || running || timer !== null || !hasActiveRows()) {
            return;
        }

        timer = setTimeout(() => void tick(), INTERVAL_MS);
    }

    // Starts polling when the page (or the next page of the history) has a non-final row.
    watch(rows, schedule, { immediate: true });

    onBeforeUnmount(() => {
        disposed = true;

        if (timer !== null) {
            clearTimeout(timer);
        }

        http.cancel();
        showHttp.cancel();
    });

    return { failing };
}
