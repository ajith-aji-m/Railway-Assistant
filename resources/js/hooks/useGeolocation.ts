import { useCallback, useState } from 'react';
import type { LatLng } from '@/types/railway';

export type GeoErrorReason = 'unavailable' | 'timeout' | 'unsupported';

export type GeoState =
    | { status: 'idle' }
    | { status: 'detecting' }
    | { status: 'denied' }
    | { status: 'error'; reason: GeoErrorReason; message: string }
    | { status: 'located'; position: LatLng; accuracy: number };

const OPTIONS: PositionOptions = { enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 };

/** GeolocationPositionError codes (spec): 1 denied, 2 unavailable, 3 timeout. */
const PERMISSION_DENIED = 1;
const TIMEOUT = 3;

/**
 * One position request through the browser Geolocation API, resolved to a state.
 * Pure (takes the geolocation object) so it can be tested without a browser.
 */
export function requestPosition(geolocation: Geolocation | undefined, options: PositionOptions = OPTIONS): Promise<GeoState> {
    if (!geolocation) {
        return Promise.resolve({ status: 'error', reason: 'unsupported', message: 'Geolocation is not supported by this browser.' });
    }

    return new Promise((resolve) => {
        geolocation.getCurrentPosition(
            ({ coords }) => resolve({ status: 'located', position: { lat: coords.latitude, lng: coords.longitude }, accuracy: coords.accuracy }),
            (error) => {
                if (error.code === PERMISSION_DENIED) resolve({ status: 'denied' });
                else if (error.code === TIMEOUT) resolve({ status: 'error', reason: 'timeout', message: 'Location request timed out.' });
                else resolve({ status: 'error', reason: 'unavailable', message: error.message || 'Unable to detect location.' });
            },
            options,
        );
    });
}

export function useGeolocation() {
    const [state, setState] = useState<GeoState>({ status: 'idle' });

    const locate = useCallback(async (): Promise<LatLng | null> => {
        setState({ status: 'detecting' });
        const result = await requestPosition(typeof navigator !== 'undefined' ? navigator.geolocation : undefined);
        setState(result);
        return result.status === 'located' ? result.position : null;
    }, []);

    return { state, locate, setState };
}

/** Resolves true when the browser has already granted location access (no prompt needed). */
export async function hasLocationPermission(): Promise<boolean> {
    try {
        const result = await navigator.permissions?.query({ name: 'geolocation' as PermissionName });
        return result?.state === 'granted';
    } catch {
        return false;
    }
}
