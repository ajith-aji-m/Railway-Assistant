import { distanceKm, nearestSegmentIndex } from '@/lib/geo';
import type { LatLng, LiveStatus } from '@/types/railway';

/**
 * Cost-controlled live tracking — pure helpers.
 *
 * RailRadar fixes (coordinates + lastUpdatedAt) are the only truth. Between two
 * real fixes the marker may glide A → B as a *visual transition*; it always ends
 * exactly on the latest real fix and never moves beyond it (no extrapolation,
 * no timetable speed). Nothing here performs network requests.
 */

export const MIN_GLIDE_MS = 800;
export const MAX_GLIDE_MS = 4000;

export interface TrackingPlan {
    /** animate: glide previous real fix → current real fix; static: show one position as-is. */
    mode: 'animate' | 'static' | 'none';
    from: LatLng | null;
    to: LatLng | null;
    /** Real time between the two fixes (ms); drives the glide duration. */
    gapMs: number;
    /** True when the shown position is not a fresh real fix (stale / missing / estimated). */
    estimate: boolean;
    /** Identity of the authoritative snapshot (changes only when RailRadar reports a new fix). */
    key: string;
}

const toLatLng = (p: { lat: number; lng: number }): LatLng => ({ lat: p.lat, lng: p.lng });

export function planTracking(live: LiveStatus): TrackingPlan {
    const snap = live.snapshot ?? null;
    const shown = live.position ? toLatLng(live.position) : null;

    // Mock data (no snapshot): unchanged behaviour — show the simulated position.
    if (!snap) {
        return { mode: shown ? 'static' : 'none', from: null, to: shown, gapMs: 0, estimate: live.gps === 'lost', key: `mock:${live.updatedAt}` };
    }

    const last = snap.lastKnown;
    const target = shown ?? (last ? toLatLng(last) : null);
    const key = `${live.journeyDate}:${last?.at ?? 'none'}:${snap.authoritative}`;

    if (!target) return { mode: 'none', from: null, to: null, gapMs: 0, estimate: true, key };

    // Only a fresh, real pair may animate.
    if (snap.authoritative && !snap.stale && snap.previous && last) {
        const gapMs = Date.parse(last.at) - Date.parse(snap.previous.at);
        if (gapMs > 0) {
            return { mode: 'animate', from: toLatLng(snap.previous), to: target, gapMs, estimate: false, key };
        }
    }

    return { mode: 'static', from: null, to: target, gapMs: 0, estimate: !snap.authoritative || snap.stale || live.gps === 'lost', key };
}

/** Glide length: the real gap between fixes, compressed into a short transition. */
export function glideDuration(gapMs: number): number {
    if (gapMs <= 0) return 0;
    return Math.min(MAX_GLIDE_MS, Math.max(MIN_GLIDE_MS, gapMs / 60));
}

/** Path from A to B following the route line between them (falls back to a straight line). */
export function pathBetween(route: [number, number][], from: LatLng, to: LatLng): LatLng[] {
    if (route.length < 2) return [from, to];
    const a = nearestSegmentIndex(route, from);
    const b = nearestSegmentIndex(route, to);
    if (a >= b) return [from, to];
    return [from, ...route.slice(a + 1, b + 1).map(([lat, lng]) => ({ lat, lng })), to];
}

/** Point at `fraction` (0..1) of the path length. */
export function pointAlong(path: LatLng[], fraction: number): LatLng {
    const f = Math.min(1, Math.max(0, fraction));
    if (path.length === 1 || f === 0) return path[0];
    if (f === 1) return path[path.length - 1];

    const legs = path.slice(1).map((p, i) => distanceKm(path[i], p));
    const total = legs.reduce((s, d) => s + d, 0);
    if (total === 0) return path[path.length - 1];

    let remaining = f * total;
    for (let i = 0; i < legs.length; i++) {
        if (remaining <= legs[i]) {
            const t = legs[i] === 0 ? 1 : remaining / legs[i];
            return { lat: path[i].lat + (path[i + 1].lat - path[i].lat) * t, lng: path[i].lng + (path[i + 1].lng - path[i].lng) * t };
        }
        remaining -= legs[i];
    }
    return path[path.length - 1];
}

/** Ease-in-out so the marker doesn't start/stop abruptly. */
const ease = (t: number) => (t < 0.5 ? 2 * t * t : 1 - (-2 * t + 2) ** 2 / 2);

interface AnimatorDeps {
    raf: (cb: (t: number) => void) => number;
    caf: (id: number) => void;
    onFrame: (position: LatLng, done: boolean) => void;
}

/**
 * Drives one glide with requestAnimationFrame and stops as soon as it reaches
 * the target (no idle loop → battery friendly). Purely local: it only calls
 * `onFrame`; it never fetches or reloads anything.
 */
export function createGlideAnimator({ raf, caf, onFrame }: AnimatorDeps) {
    let frame: number | null = null;

    const stop = () => {
        if (frame !== null) caf(frame);
        frame = null;
    };

    return {
        start(path: LatLng[], durationMs: number) {
            stop();
            if (durationMs <= 0 || path.length < 2) {
                onFrame(path[path.length - 1], true);
                return;
            }
            let startedAt: number | null = null;
            const step = (t: number) => {
                startedAt ??= t;
                const progress = Math.min(1, (t - startedAt) / durationMs);
                const done = progress >= 1;
                onFrame(pointAlong(path, ease(progress)), done);
                frame = done ? null : raf(step);
            };
            frame = raf(step);
        },
        stop,
        get running() {
            return frame !== null;
        },
    };
}
