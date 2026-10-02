// Props shared with every page by app/Http/Middleware/HandleInertiaRequests.php.
export interface SharedProps {
    app: { name: string; version: string };
    /** "live" = real railway data, "demo" = built-in sample timetable. */
    dataSource: 'live' | 'demo';
    /** Polling interval per live screen, in seconds (0 = manual refresh only). */
    liveRefresh: { station: number; train: number; map: number };
    [key: string]: unknown;
}
