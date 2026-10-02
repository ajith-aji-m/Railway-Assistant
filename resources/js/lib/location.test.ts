import { describe, expect, it, vi } from 'vitest';
import { LOCATION_MAX_AGE_MS, restoreLocation, toSavedLocation, validSavedLocation } from './location';

const NOW = 1_790_000_000_000;
const MADURAI = { lat: 9.9252, lng: 78.1198 };

describe('saved location', () => {
    it('stores latitude, longitude and timestamp', () => {
        expect(toSavedLocation(MADURAI, NOW)).toEqual({ lat: 9.9252, lng: 78.1198, timestamp: NOW });
    });

    it('reuses a recent saved position', () => {
        expect(validSavedLocation(toSavedLocation(MADURAI, NOW - 60_000), NOW)).toEqual(MADURAI);
    });

    it('ignores an expired position', () => {
        expect(validSavedLocation(toSavedLocation(MADURAI, NOW - LOCATION_MAX_AGE_MS - 1), NOW)).toBeNull();
    });

    it.each([
        ['nothing saved', null],
        ['old format without timestamp', { lat: 9.9, lng: 78.1 }],
        ['non-numeric', { lat: '9.9', lng: 78.1, timestamp: NOW }],
        ['NaN', { lat: Number.NaN, lng: 78.1, timestamp: NOW }],
        ['latitude out of range', { lat: 91, lng: 78.1, timestamp: NOW }],
        ['longitude out of range', { lat: 9.9, lng: 181, timestamp: NOW }],
        ['timestamp far in the future', { lat: 9.9, lng: 78.1, timestamp: NOW + 3_600_000 }],
        ['not an object', 'garbage'],
    ])('ignores an invalid value (%s)', (_, value) => {
        expect(validSavedLocation(value, NOW)).toBeNull();
    });
});

describe('restoreLocation', () => {
    it('reuses the saved position without asking the browser again', async () => {
        const permissionGranted = vi.fn(async () => true);
        const locate = vi.fn(async () => ({ lat: 1, lng: 1 }));

        expect(await restoreLocation({ saved: () => MADURAI, permissionGranted, locate })).toEqual(MADURAI);
        expect(permissionGranted).not.toHaveBeenCalled();
        expect(locate).not.toHaveBeenCalled();
    });

    it('detects silently when permission was already granted', async () => {
        const locate = vi.fn(async () => MADURAI);

        expect(await restoreLocation({ saved: () => null, permissionGranted: async () => true, locate })).toEqual(MADURAI);
        expect(locate).toHaveBeenCalledTimes(1);
    });

    it('never requests location when permission has not been granted (manual search only)', async () => {
        const locate = vi.fn(async () => MADURAI);

        expect(await restoreLocation({ saved: () => null, permissionGranted: async () => false, locate })).toBeNull();
        expect(locate).not.toHaveBeenCalled();
    });

    it('falls back to manual search when detection fails', async () => {
        expect(await restoreLocation({ saved: () => null, permissionGranted: async () => true, locate: async () => null })).toBeNull();
    });
});
