import type { BoardEntry } from '@/types/railway';

export interface BoardTimeInfo {
    /** "expected" only when backed by live data; otherwise the time is the timetable. */
    kind: 'expected' | 'scheduled' | 'cancelled';
    /** Secondary label next to the scheduled time ("Exp 06:42"), or null. */
    expectedLabel: string | null;
    delayed: boolean;
    /** Show the delay pill (+7m / On time) — only with live delay data. */
    showDelay: boolean;
}

/** How a station-board card presents its time. All times come from the backend. */
export function boardTimeInfo(entry: BoardEntry): BoardTimeInfo {
    if (entry.status === 'cancelled') {
        return { kind: 'cancelled', expectedLabel: null, delayed: false, showDelay: false };
    }
    if (!entry.isLive) {
        return { kind: 'scheduled', expectedLabel: null, delayed: false, showDelay: false };
    }
    return {
        kind: 'expected',
        expectedLabel: `Exp ${entry.expectedTime ?? '—'}`,
        delayed: (entry.delayMinutes ?? 0) > 0,
        showDelay: entry.delayMinutes !== null,
    };
}
