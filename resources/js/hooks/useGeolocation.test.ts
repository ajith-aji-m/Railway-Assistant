import { describe, expect, it } from 'vitest';
import { requestPosition } from './useGeolocation';

function fakeGeolocation(result: { coords?: Partial<GeolocationCoordinates>; errorCode?: number; message?: string }): Geolocation {
    return {
        getCurrentPosition: (success: PositionCallback, failure?: PositionErrorCallback | null) => {
            if (result.coords) {
                success({ coords: { accuracy: 12, ...result.coords } as GeolocationCoordinates, timestamp: 0 } as GeolocationPosition);
            } else {
                failure?.({ code: result.errorCode!, message: result.message ?? '' } as GeolocationPositionError);
            }
        },
        watchPosition: () => 0,
        clearWatch: () => undefined,
    } as Geolocation;
}

describe('requestPosition (browser geolocation)', () => {
    it('resolves the position on success', async () => {
        const state = await requestPosition(fakeGeolocation({ coords: { latitude: 13.0604, longitude: 80.2496 } }));
        expect(state).toEqual({ status: 'located', position: { lat: 13.0604, lng: 80.2496 }, accuracy: 12 });
    });

    it('reports permission denied', async () => {
        expect(await requestPosition(fakeGeolocation({ errorCode: 1 }))).toEqual({ status: 'denied' });
    });

    it('reports position unavailable', async () => {
        const state = await requestPosition(fakeGeolocation({ errorCode: 2, message: 'Position unavailable' }));
        expect(state).toEqual({ status: 'error', reason: 'unavailable', message: 'Position unavailable' });
    });

    it('reports a timeout', async () => {
        const state = await requestPosition(fakeGeolocation({ errorCode: 3 }));
        expect(state).toMatchObject({ status: 'error', reason: 'timeout' });
    });

    it('handles browsers without geolocation', async () => {
        expect(await requestPosition(undefined)).toMatchObject({ status: 'error', reason: 'unsupported' });
    });
});
