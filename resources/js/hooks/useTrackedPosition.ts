import { useEffect, useMemo, useRef, useState } from 'react';
import { createGlideAnimator, glideDuration, pathBetween, planTracking } from '@/lib/tracking';
import type { LatLng, LiveStatus } from '@/types/railway';

/**
 * Marker position for the live map: real RailRadar fixes, with a short local
 * glide between two real fixes. Animation is client-only (no requests).
 */
export function useTrackedPosition(live: LiveStatus, route: [number, number][]) {
    const plan = useMemo(() => planTracking(live), [live]);
    const [position, setPosition] = useState<LatLng | null>(plan.mode === 'animate' ? plan.from : plan.to);
    const displayed = useRef<LatLng | null>(position);

    const animator = useMemo(
        () =>
            createGlideAnimator({
                raf: (cb) => window.requestAnimationFrame(cb),
                caf: (id) => window.cancelAnimationFrame(id),
                onFrame: (p) => {
                    displayed.current = p;
                    setPosition(p);
                },
            }),
        [],
    );

    useEffect(() => {
        if (plan.mode === 'animate' && plan.from && plan.to) {
            // Start from where the marker is now (reconciles mid-glide), else the previous real fix.
            const from = displayed.current ?? plan.from;
            animator.start(pathBetween(route, from, plan.to), glideDuration(plan.gapMs));
        } else {
            animator.stop();
            displayed.current = plan.to;
            setPosition(plan.to);
        }
        // Re-plan only when RailRadar reports a new authoritative snapshot (or the shown point changes).
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [plan.key, plan.mode, plan.to?.lat, plan.to?.lng]);

    useEffect(() => () => animator.stop(), [animator]);

    return { position, estimate: plan.estimate, animating: plan.mode === 'animate' };
}
