import { useSyncExternalStore } from 'react';
import { readStorage, writeStorage } from '@/lib/storage';

/** Tiny localStorage-backed store shared by every component that uses it. */
export function createStore<T>(key: string, initial: T) {
    let value: T | undefined;
    const listeners = new Set<() => void>();

    const get = (): T => {
        if (value === undefined) value = typeof window === 'undefined' ? initial : readStorage(key, initial);
        return value;
    };

    const set = (next: T | ((prev: T) => T)) => {
        value = typeof next === 'function' ? (next as (prev: T) => T)(get()) : next;
        writeStorage(key, value);
        listeners.forEach((l) => l());
    };

    const subscribe = (listener: () => void) => {
        listeners.add(listener);
        return () => listeners.delete(listener);
    };

    const useStore = () => useSyncExternalStore(subscribe, get, () => initial);

    return { get, set, useStore };
}
