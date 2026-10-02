// localStorage can be unavailable (private mode, blocked storage) — never throw.

export function readStorage<T>(key: string, fallback: T): T {
    try {
        const raw = window.localStorage.getItem(key);
        return raw === null ? fallback : (JSON.parse(raw) as T);
    } catch {
        return fallback;
    }
}

export function writeStorage<T>(key: string, value: T): void {
    try {
        window.localStorage.setItem(key, JSON.stringify(value));
    } catch {
        // ignore
    }
}

export function removeStorage(key: string): void {
    try {
        window.localStorage.removeItem(key);
    } catch {
        // ignore
    }
}
