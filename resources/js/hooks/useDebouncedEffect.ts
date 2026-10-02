import { useEffect, useRef } from 'react';

/** Runs `effect` after `delay` ms of `deps` being stable; skips the first render. */
export function useDebouncedEffect(effect: () => void, deps: unknown[], delay = 250) {
    const first = useRef(true);
    useEffect(() => {
        if (first.current) {
            first.current = false;
            return;
        }
        const id = window.setTimeout(effect, delay);
        return () => window.clearTimeout(id);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, deps);
}
