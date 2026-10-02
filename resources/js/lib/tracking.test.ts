import { afterEach, describe, expect, it, vi } from 'vitest';
import type { LiveStatus, PositionFix } from '@/types/railway';
import { withLastKnownPosition } from './live';
import { createGlideAnimator, glideDuration, MAX_GLIDE_MS, MIN_GLIDE_MS, pathBetween, planTracking, pointAlong } from './tracking';

// Real RailRadar fixes for 12675 (10:42:12 and 10:46:14 IST).
const A: PositionFix = { lat: 11.875847, lng: 78.15302, at: '2026-10-02T10:42:12+05:30' };
const B: PositionFix = { lat: 11.853142, lng: 78.12888, at: '2026-10-02T10:46:14+05:30' };

function live(overrides: Partial<LiveStatus> = {}): LiveStatus {
    return {
        status: 'running',
        gps: 'active',
        delayMinutes: 7,
        speedKmh: null,
        position: { lat: B.lat, lng: B.lng },
        atStation: false,
        lastStopSequence: 63,
        nextStopSequence: 72,
        distanceFromLastKm: 43.5,
        distanceToNextKm: 22.7,
        journeyDate: '2026-10-02',
        updatedAt: B.at,
        stops: [],
        delayIsLive: true,
        snapshot: { authoritative: true, trackingMode: 'real-time', previous: A, lastKnown: B, stale: false },
        ...overrides,
    };
}

describe('planTracking (real snapshots only)', () => {
    it('animates between two real fixes, using their timestamps', () => {
        const plan = planTracking(live());
        expect(plan.mode).toBe('animate');
        expect(plan.from).toEqual({ lat: A.lat, lng: A.lng });
        expect(plan.to).toEqual({ lat: B.lat, lng: B.lng });
        expect(plan.gapMs).toBe(242_000); // 10:46:14 − 10:42:12
        expect(plan.estimate).toBe(false);
    });

    it('freezes on a single real fix (no fabricated movement)', () => {
        const plan = planTracking(live({ snapshot: { authoritative: true, trackingMode: 'real-time', previous: null, lastKnown: B, stale: false } }));
        expect(plan).toMatchObject({ mode: 'static', from: null, to: { lat: B.lat, lng: B.lng }, gapMs: 0 });
    });

    it('keeps the last real fix, unmoving, when coordinates are missing', () => {
        const plan = planTracking(live({ position: null, gps: 'lost', snapshot: { authoritative: false, trackingMode: 'real-time', previous: null, lastKnown: A, stale: false } }));
        expect(plan).toMatchObject({ mode: 'static', to: { lat: A.lat, lng: A.lng }, estimate: true });
    });

    it('does not animate a stale snapshot', () => {
        const plan = planTracking(live({ gps: 'lost', snapshot: { authoritative: true, trackingMode: 'real-time', previous: A, lastKnown: B, stale: true } }));
        expect(plan).toMatchObject({ mode: 'static', estimate: true });
    });

    it('does not animate backwards or between identical timestamps', () => {
        const same = planTracking(live({ snapshot: { authoritative: true, trackingMode: 'real-time', previous: B, lastKnown: B, stale: false } }));
        expect(same.mode).toBe('static');
    });

    it('ignores timetable data entirely (no speed from schedules)', () => {
        const withTimetable = live({
            stops: [
                {
                    sequence: 63, station: { code: 'MAP', name: 'Morappur' }, lat: 12.12, lng: 78.39,
                    scheduledArrival: '09:59', scheduledDeparture: '10:00', expectedArrival: '10:07', expectedDeparture: '10:17',
                    platform: '3', distanceKm: 268, delayMinutes: 8, state: 'departed',
                },
            ],
            distanceToNextKm: 999,
        });
        expect(planTracking(withTimetable)).toEqual(planTracking(live()));
        expect(planTracking(live()).gapMs).toBe(Date.parse(B.at) - Date.parse(A.at));
    });

    it('keeps mock mode unchanged (simulated position, no snapshot)', () => {
        const mock = live({ snapshot: null, position: { lat: 12.85, lng: 78.96 }, speedKmh: 71 });
        expect(planTracking(mock)).toMatchObject({ mode: 'static', to: { lat: 12.85, lng: 78.96 }, estimate: false });
    });
});

describe('glide geometry', () => {
    it('derives the glide length from the real gap, bounded to a short transition', () => {
        expect(glideDuration(120_000)).toBe(2_000); // 2-minute gap → 2 s glide
        expect(glideDuration(242_000)).toBe(MAX_GLIDE_MS); // real 12675 gap (4m02s) → capped
        expect(glideDuration(1_000)).toBe(MIN_GLIDE_MS);
        expect(glideDuration(10 * 60_000)).toBe(MAX_GLIDE_MS);
        expect(glideDuration(0)).toBe(0);
    });

    it('follows the route line between the two fixes', () => {
        const route: [number, number][] = [[12.12439, 78.39411], [11.9, 78.2], [11.86, 78.14], [11.70321, 78.11049]];
        const path = pathBetween(route, A, B);
        expect(path[0]).toEqual(A);
        expect(path[path.length - 1]).toEqual(B);
    });

    it('interpolates by distance and ends exactly on the latest real fix', () => {
        const path = [A, B];
        expect(pointAlong(path, 0)).toEqual(A);
        expect(pointAlong(path, 1)).toEqual(B);
        const mid = pointAlong(path, 0.5);
        expect(mid.lat).toBeCloseTo((A.lat + B.lat) / 2, 6);
        expect(mid.lng).toBeCloseTo((A.lng + B.lng) / 2, 6);
    });
});

describe('createGlideAnimator', () => {
    afterEach(() => vi.unstubAllGlobals());

    /** Fake requestAnimationFrame / cancelAnimationFrame with real cancel semantics. */
    function fakeFrames() {
        const queue = new Map<number, (t: number) => void>();
        let id = 0;
        return {
            raf: vi.fn((cb: (t: number) => void) => (queue.set(++id, cb), id)),
            caf: vi.fn((frameId: number) => void queue.delete(frameId)),
            run(times: number[]) {
                for (const t of times) {
                    const next = queue.entries().next().value;
                    if (!next) return;
                    queue.delete(next[0]);
                    next[1](t);
                }
            },
            pending: () => queue.size,
        };
    }

    it('moves A → B over the glide, stops at B and stops requesting frames', () => {
        const frames = fakeFrames();
        const positions: { lat: number; done: boolean }[] = [];
        const animator = createGlideAnimator({ raf: frames.raf, caf: frames.caf, onFrame: (p, done) => positions.push({ lat: p.lat, done }) });

        animator.start([A, B], 1000);
        frames.run([0, 250, 500, 750, 1000, 1250]);

        expect(positions[0].lat).toBeCloseTo(A.lat, 6);
        expect(positions.at(-1)).toEqual({ lat: B.lat, done: true });
        const lats = positions.map((p) => p.lat);
        expect([...lats].sort((x, y) => y - x)).toEqual(lats); // monotonic toward B (lat decreasing)
        expect(frames.pending()).toBe(0); // no idle animation loop
        expect(animator.running).toBe(false);
    });

    it('reconciles to a new real fix mid-glide by restarting from the shown point', () => {
        const frames = fakeFrames();
        let shown = { lat: A.lat, lng: A.lng };
        const animator = createGlideAnimator({ raf: frames.raf, caf: frames.caf, onFrame: (p) => (shown = p) });

        animator.start([A, B], 1000);
        frames.run([0, 500]);
        const midway = shown;
        const C = { lat: 11.8, lng: 78.1 };
        animator.start([midway, C], 1000); // new authoritative target
        frames.run([0, 1000]);

        expect(frames.caf).toHaveBeenCalled(); // old glide cancelled
        expect(shown).toEqual(C);
    });

    it('jumps straight to the target when there is nothing to animate', () => {
        const frames = fakeFrames();
        const onFrame = vi.fn();
        createGlideAnimator({ raf: frames.raf, caf: frames.caf, onFrame }).start([B], 0);
        expect(onFrame).toHaveBeenCalledWith(B, true);
        expect(frames.raf).not.toHaveBeenCalled();
    });

    it('never makes network requests while animating', () => {
        const fetchSpy = vi.fn();
        const xhrSpy = vi.fn();
        vi.stubGlobal('fetch', fetchSpy);
        vi.stubGlobal('XMLHttpRequest', xhrSpy);
        const frames = fakeFrames();
        const animator = createGlideAnimator({ raf: frames.raf, caf: frames.caf, onFrame: () => undefined });

        animator.start(pathBetween([], A, B), 1000);
        frames.run(Array.from({ length: 120 }, (_, i) => i * 16));

        expect(fetchSpy).not.toHaveBeenCalled();
        expect(xhrSpy).not.toHaveBeenCalled();
    });
});

describe('missing coordinates / failed refresh', () => {
    it('uses the server-kept last real fix when a response has no coordinates', () => {
        const next = live({ position: null, gps: 'lost', updatedAt: '2026-10-02T10:50:00+05:30', snapshot: { authoritative: false, trackingMode: 'real-time', previous: null, lastKnown: A, stale: false } });
        const merged = withLastKnownPosition(null, next);

        expect(merged.position).toEqual({ lat: A.lat, lng: A.lng });
        expect(merged.gps).toBe('lost'); // never an active fix
        expect(merged.positionStaleSince).toBe(A.at);
        expect(merged.updatedAt).toBe('2026-10-02T10:50:00+05:30'); // RailRadar timestamp untouched
    });

    it('keeps the previous data object when a refresh fails (no new props arrive)', () => {
        const current = live();
        // A failed partial reload delivers no new props, so the page keeps rendering `current`.
        expect(planTracking(current)).toEqual(planTracking(current));
    });
});
