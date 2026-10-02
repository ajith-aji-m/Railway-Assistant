import { describe, expect, it } from 'vitest';
import type { BoardEntry } from '@/types/railway';
import { boardTimeInfo, groupByPhase } from './board';
import { urls } from './urls';

const base: BoardEntry = {
    trainNumber: '19419',
    trainName: 'Tiruchchirappalli Weekly Express',
    trainType: 'Express',
    from: { code: 'MS', name: 'MS' },
    to: { code: 'TPJ', name: 'TPJ' },
    scheduledTime: '15:10',
    expectedTime: '15:22',
    platform: '6',
    delayMinutes: 12,
    status: 'expected',
    isLive: true,
    phase: 'running',
};

describe('station board time labels', () => {
    it('shows a live expected time with its delay', () => {
        expect(boardTimeInfo(base)).toEqual({ kind: 'expected', expectedLabel: 'Exp 3:22 PM', delayed: true, showDelay: true });
    });

    it('shows on-time live trains as expected, not delayed', () => {
        expect(boardTimeInfo({ ...base, expectedTime: '15:10', delayMinutes: 0 })).toMatchObject({ expectedLabel: 'Exp 3:10 PM', delayed: false, showDelay: true });
    });

    it('never labels a timetable-only time as expected', () => {
        const info = boardTimeInfo({ ...base, isLive: false, status: 'scheduled', expectedTime: null, delayMinutes: null });
        expect(info).toEqual({ kind: 'scheduled', expectedLabel: null, delayed: false, showDelay: false });
    });

    it('shows no time or delay for cancelled trains', () => {
        expect(boardTimeInfo({ ...base, status: 'cancelled' })).toMatchObject({ kind: 'cancelled', expectedLabel: null, showDelay: false });
    });
});

describe('train selection links', () => {
    it('uses the existing Train Details and Live Map routes with the real train number', () => {
        expect(urls.train('19419')).toBe('/trains/19419');
        expect(urls.trainMap('19419')).toBe('/trains/19419/map');
        expect(urls.train('06028')).toBe('/trains/06028'); // leading zero preserved
    });
});

describe('full-day board groups', () => {
    const entry = (trainNumber: string, scheduledTime: string, phase: BoardEntry['phase'], status: BoardEntry['status'] = 'expected'): BoardEntry => ({
        ...base,
        trainNumber,
        scheduledTime,
        phase,
        status,
    });

    it('shows completed, running and upcoming trains, in that order, keeping time order inside each group', () => {
        const board = [
            entry('A', '03:20', 'completed', 'arrived'),
            entry('B', '06:10', 'completed', 'departed'),
            entry('C', '10:45', 'running'),
            entry('D', '11:00', 'running', 'at_station'),
            entry('E', '13:25', 'upcoming', 'scheduled'),
            entry('F', '21:30', 'upcoming', 'scheduled'),
        ];
        const groups = groupByPhase(board);

        expect(groups.map((g) => g.label)).toEqual(['Completed', 'Running', 'Upcoming']);
        expect(groups.map((g) => g.entries.map((e) => e.trainNumber))).toEqual([['A', 'B'], ['C', 'D'], ['E', 'F']]);
    });

    it('keeps cancelled trains in the group of their time, still marked cancelled', () => {
        const groups = groupByPhase([entry('X', '20:00', 'upcoming', 'cancelled')]);
        expect(groups).toEqual([{ phase: 'upcoming', label: 'Upcoming', entries: [expect.objectContaining({ trainNumber: 'X', status: 'cancelled' })] }]);
    });

    it('leaves out empty groups', () => {
        expect(groupByPhase([entry('C', '10:45', 'running')]).map((g) => g.phase)).toEqual(['running']);
        expect(groupByPhase([])).toEqual([]);
    });

    it('formats board times as h:mm AM/PM', () => {
        expect(boardTimeInfo({ ...base, expectedTime: '00:15' }).expectedLabel).toBe('Exp 12:15 AM');
    });
});
