import type { LatLng } from '@/types/railway';

/** A detected position as saved in the browser (localStorage only). */
export interface SavedLocation extends LatLng {
    /** When the position was detected (ms since epoch). */
    timestamp: number;
}

/** How long a saved position is trusted before it has to be detected again. */
export const LOCATION_MAX_AGE_MS = 30 * 60 * 1000;

/** Small allowance for clock differences when the timestamp is slightly in the future. */
const CLOCK_SKEW_MS = 60 * 1000;

export function toSavedLocation(position: LatLng, now = Date.now()): SavedLocation {
    return { lat: position.lat, lng: position.lng, timestamp: now };
}

const isNumber = (v: unknown): v is number => typeof v === 'number' && Number.isFinite(v);

/** The saved position when it is well-formed and recent; anything else (old format, expired, corrupt) is ignored. */
export function validSavedLocation(value: unknown, now = Date.now(), maxAgeMs = LOCATION_MAX_AGE_MS): LatLng | null {
    if (!value || typeof value !== 'object') return null;
    const { lat, lng, timestamp } = value as Record<string, unknown>;
    if (!isNumber(lat) || !isNumber(lng) || !isNumber(timestamp)) return null;
    if (lat < -90 || lat > 90 || lng < -180 || lng > 180) return null;
    if (timestamp > now + CLOCK_SKEW_MS || now - timestamp > maxAgeMs) return null;
    return { lat, lng };
}

/**
 * Position to open the station screen with, without ever showing a permission
 * prompt: a valid saved position is reused as is; otherwise the browser is asked
 * only when location access was already granted. Null → manual station search.
 */
export async function restoreLocation(deps: {
    saved: () => LatLng | null;
    permissionGranted: () => Promise<boolean>;
    locate: () => Promise<LatLng | null>;
}): Promise<LatLng | null> {
    const saved = deps.saved();
    if (saved) return saved;
    if (!(await deps.permissionGranted())) return null;
    return deps.locate();
}
