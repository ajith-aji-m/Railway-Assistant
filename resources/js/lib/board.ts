import { to12h } from '@/lib/format';
import type { BoardEntry, BoardPhase } from '@/types/railway';

export interface BoardTimeInfo {
    /** "expected" only when backed by live data; otherwise the time is the timetable. */
    kind: 'expected' | 'scheduled' | 'cancelled';
    /** Secondary label next to the scheduled time ("Exp 6:42 AM"), or null. */
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
        expectedLabel: `Exp ${entry.expectedTime ? to12h(entry.expectedTime) : '—'}`,
        delayed: (entry.delayMinutes ?? 0) > 0,
        showDelay: entry.delayMinutes !== null,
    };
}

export interface BoardGroup {
    phase: BoardPhase;
    label: string;
    entries: BoardEntry[];
}

const PHASES: { phase: BoardPhase; label: string }[] = [
    { phase: 'completed', label: 'Completed' },
    { phase: 'running', label: 'Running' },
    { phase: 'upcoming', label: 'Upcoming' },
];

/**
 * Full-day board split into Completed → Running → Upcoming (in that order); each group
 * keeps the backend's chronological order. Empty groups are left out.
 */
export function groupByPhase(entries: BoardEntry[]): BoardGroup[] {
    return PHASES.map(({ phase, label }) => ({ phase, label, entries: entries.filter((e) => e.phase === phase) })).filter((g) => g.entries.length > 0);
}
