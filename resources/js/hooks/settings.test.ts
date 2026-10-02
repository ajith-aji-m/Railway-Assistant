import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/** Minimal in-memory localStorage shared across "page loads" (module re-imports). */
function installLocalStorage() {
    const data = new Map<string, string>();
    const localStorage = {
        getItem: (k: string) => (data.has(k) ? data.get(k)! : null),
        setItem: (k: string, v: string) => void data.set(k, String(v)),
        removeItem: (k: string) => void data.delete(k),
        clear: () => data.clear(),
    };
    vi.stubGlobal('window', { localStorage });
    return data;
}

/** Fresh module instances = a new page load reading from the same localStorage. */
async function reload() {
    vi.resetModules();
    const settings = await import('./useSettings');
    const recent = await import('./useRecentSearches');
    return { settings, recent };
}

describe('local settings', () => {
    let storage: Map<string, string>;
    const fetchSpy = vi.fn();

    beforeEach(() => {
        storage = installLocalStorage();
        vi.stubGlobal('fetch', fetchSpy);
    });
    afterEach(() => {
        vi.unstubAllGlobals();
        fetchSpy.mockReset();
    });

    it('loads defaults when nothing is saved', async () => {
        const { settings } = await reload();
        expect(settings.settingsStore.get()).toEqual({ useLocation: true, distanceUnit: 'km', notifications: true });
    });

    it('loads previously saved settings', async () => {
        storage.set('railway.settings', JSON.stringify({ useLocation: false, distanceUnit: 'mi', notifications: true }));
        const { settings } = await reload();
        expect(settings.settingsStore.get()).toMatchObject({ useLocation: false, distanceUnit: 'mi' });
    });

    it('persists changes under the existing key and survives a reload', async () => {
        let { settings } = await reload();
        settings.settingsStore.set((prev) => ({ ...prev, distanceUnit: 'mi', useLocation: false }));
        expect(JSON.parse(storage.get('railway.settings')!)).toMatchObject({ distanceUnit: 'mi', useLocation: false });

        ({ settings } = await reload());
        expect(settings.settingsStore.get()).toMatchObject({ distanceUnit: 'mi', useLocation: false });
        expect([...storage.keys()]).toEqual(['railway.settings']); // no duplicate storage
    });

    it('clears recent searches locally only', async () => {
        storage.set('railway.recentSearches', JSON.stringify([{ kind: 'station', code: 'MAS', label: 'MGR Chennai Central' }]));
        storage.set('railway.settings', JSON.stringify({ useLocation: true, distanceUnit: 'km', notifications: true }));
        const { recent } = await reload();
        expect(recent.recentSearchesStore.get()).toHaveLength(1);

        recent.recentSearchesStore.set([]);

        expect(JSON.parse(storage.get('railway.recentSearches')!)).toEqual([]);
        expect(storage.has('railway.settings')).toBe(true); // other settings untouched
        expect(fetchSpy).not.toHaveBeenCalled(); // no network request
    });

    it('keeps working when localStorage is unavailable', async () => {
        vi.stubGlobal('window', {
            localStorage: {
                getItem: () => {
                    throw new Error('blocked');
                },
                setItem: () => {
                    throw new Error('blocked');
                },
            },
        });
        const { settings } = await reload();
        expect(settings.settingsStore.get()).toEqual(settings.DEFAULT_SETTINGS);
        expect(() => settings.settingsStore.set((prev) => ({ ...prev, distanceUnit: 'mi' }))).not.toThrow();
    });
});
