/** Trim and collapse whitespace; the form a query is sent (and compared) in. */
export function normalizeQuery(raw: string): string {
    return raw.trim().replace(/\s+/g, ' ');
}

export type QueryState = 'empty' | 'short' | 'ready';

export function queryState(raw: string, minLength: number): QueryState {
    const q = normalizeQuery(raw);
    if (q === '') return 'empty';
    return q.length < minLength ? 'short' : 'ready';
}

interface SchedulerOptions {
    delayMs: number;
    minLength: number;
    /** Called with a normalized query ('' clears results; never reaches the provider). */
    onSearch: (query: string) => void;
}

/**
 * Debounced search that skips queries shorter than `minLength` and never sends the
 * same query twice in a row (case-insensitive) — protects the API quota.
 */
export function createSearchScheduler({ delayMs, minLength, onSearch }: SchedulerOptions) {
    let timer: ReturnType<typeof setTimeout> | undefined;
    let last: string | null = null;

    const schedule = (query: string) => {
        clearTimeout(timer);
        timer = setTimeout(() => {
            const key = query.toLowerCase();
            if (key === last) return;
            last = key;
            onSearch(query);
        }, delayMs);
    };

    return {
        update(raw: string): QueryState {
            const state = queryState(raw, minLength);
            if (state === 'short') {
                clearTimeout(timer); // wait for more characters
            } else {
                schedule(state === 'empty' ? '' : normalizeQuery(raw));
            }
            return state;
        },
        /** Forget the last query so the next identical one is sent again (e.g. retry). */
        reset() {
            last = null;
        },
        /** Treat `query` as already sent (initial value from the server). */
        prime(query: string) {
            last = normalizeQuery(query).toLowerCase();
        },
        cancel() {
            clearTimeout(timer);
        },
    };
}
