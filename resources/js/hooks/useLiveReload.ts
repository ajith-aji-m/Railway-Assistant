import { router, usePoll } from '@inertiajs/react';
import { useCallback, useState } from 'react';

/**
 * Partial reload of live props with loading/error tracking, plus optional polling.
 * Used by the "Live" refresh controls on the dashboard, train details and map.
 */
export function useLiveReload(only: string[], intervalMs?: number, options: { keepDataOnPollError?: boolean } = {}) {
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(false);
    // Background poll failed but the last good data is still shown (keepDataOnPollError).
    const [stale, setStale] = useState(false);
    const pollFailed = () => {
        if (options.keepDataOnPollError) setStale(true);
        else setError(true);
        return false;
    };

    const callbacks = {
        onStart: () => setLoading(true),
        onSuccess: () => {
            setError(false);
            setStale(false);
        },
        onNetworkError: () => {
            setError(true);
            return false;
        },
        onHttpException: () => {
            setError(true);
            return false;
        },
        onFinish: () => setLoading(false),
    };

    const reload = useCallback(() => {
        router.reload({ only, ...callbacks });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [only.join(',')]);

    usePoll(
        intervalMs ?? 60_000,
        {
            only,
            onSuccess: () => {
                setError(false);
                setStale(false);
            },
            // Keep the current data on screen when a background refresh fails.
            onNetworkError: pollFailed,
            onHttpException: pollFailed,
        },
        { autoStart: !!intervalMs },
    );

    return { loading, error, stale, reload, callbacks };
}
