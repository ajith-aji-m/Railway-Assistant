import type { LatLng } from '@/types/railway';

export function distanceKm(a: LatLng, b: LatLng): number {
    const rad = (d: number) => (d * Math.PI) / 180;
    const dLat = rad(b.lat - a.lat);
    const dLng = rad(b.lng - a.lng);
    const h = Math.sin(dLat / 2) ** 2 + Math.cos(rad(a.lat)) * Math.cos(rad(b.lat)) * Math.sin(dLng / 2) ** 2;
    return 6371 * 2 * Math.atan2(Math.sqrt(h), Math.sqrt(1 - h));
}

/**
 * Index of the polyline segment [i, i+1] closest to `point` (planar approximation,
 * fine at map scale). Used to draw the completed part of a route up to the train.
 */
export function nearestSegmentIndex(line: [number, number][], point: LatLng): number {
    let best = 0;
    let bestDist = Infinity;
    const kx = Math.cos((point.lat * Math.PI) / 180); // shrink longitude by latitude

    for (let i = 0; i < line.length - 1; i++) {
        const [ay, ax] = line[i];
        const [by, bx] = line[i + 1];
        const abx = (bx - ax) * kx;
        const aby = by - ay;
        const apx = (point.lng - ax) * kx;
        const apy = point.lat - ay;
        const len2 = abx * abx + aby * aby;
        const t = len2 === 0 ? 0 : Math.max(0, Math.min(1, (apx * abx + apy * aby) / len2));
        const dx = apx - t * abx;
        const dy = apy - t * aby;
        const d = dx * dx + dy * dy;
        if (d < bestDist) {
            bestDist = d;
            best = i;
        }
    }
    return best;
}
