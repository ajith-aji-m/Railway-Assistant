import { createStore } from './createStore';

export interface RecentSearch {
    kind: 'train' | 'station';
    code: string; // train number or station code
    label: string; // name
}

const MAX = 5;
/** Browser-only (localStorage key "railway.recentSearches"); never sent to any API. */
export const recentSearchesStore = createStore<RecentSearch[]>('railway.recentSearches', []);
const store = recentSearchesStore;

export function useRecentSearches() {
    const items = store.useStore();

    const add = (item: RecentSearch) =>
        store.set((prev) => [item, ...prev.filter((p) => !(p.kind === item.kind && p.code === item.code))].slice(0, MAX));

    const clear = () => store.set([]);

    return { items, add, clear };
}
