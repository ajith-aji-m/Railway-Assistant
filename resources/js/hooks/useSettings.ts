import { createStore } from './createStore';

export type DistanceUnit = 'km' | 'mi';

export interface Settings {
    useLocation: boolean;
    distanceUnit: DistanceUnit;
    notifications: boolean;
}

export const DEFAULT_SETTINGS: Settings = {
    useLocation: true,
    distanceUnit: 'km',
    notifications: true,
};

/** Browser-only preferences (localStorage key "railway.settings"); never sent to any API. */
export const settingsStore = createStore<Settings>('railway.settings', DEFAULT_SETTINGS);

export function useSettings() {
    // Fill in defaults for keys missing from older saved settings.
    const settings: Settings = { ...DEFAULT_SETTINGS, ...settingsStore.useStore() };
    const update = (patch: Partial<Settings>) => settingsStore.set((prev) => ({ ...DEFAULT_SETTINGS, ...prev, ...patch }));
    return [settings, update] as const;
}
