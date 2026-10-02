import { router } from '@inertiajs/react';

/** Browser back when there is in-app history, otherwise go to a sensible parent. */
export function goBack(fallback: string) {
    if (window.history.length > 1 && document.referrer.startsWith(window.location.origin)) {
        window.history.back();
    } else {
        router.visit(fallback);
    }
}
