import { describe, expect, it } from 'vitest';
import { nearestSegmentIndex } from './geo';

describe('nearestSegmentIndex', () => {
    // Real RailRadar halts of 12675 around Salem: Morappur → Magnesite Jn → Salem Jn → Erode Jn.
    const route: [number, number][] = [
        [12.12439, 78.39411],
        [11.70321, 78.11049],
        [11.669601, 78.113466],
        [11.3304, 77.7263],
    ];

    it('finds the segment the train is on', () => {
        expect(nearestSegmentIndex(route, { lat: 11.875847, lng: 78.15302 })).toBe(0); // between Morappur and Magnesite
        expect(nearestSegmentIndex(route, { lat: 11.687485, lng: 78.10273 })).toBe(1); // just past Magnesite
        expect(nearestSegmentIndex(route, { lat: 11.5, lng: 77.92 })).toBe(2); // towards Erode
    });

    it('handles a single segment', () => {
        expect(nearestSegmentIndex(route.slice(0, 2), { lat: 0, lng: 0 })).toBe(0);
    });
});
