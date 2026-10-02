import { describe, expect, it } from 'vitest';
import type { BoardEntry } from '@/types/railway';
import { boardTimeInfo } from './board';
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
};

describe('station board time labels', () => {
    it('shows a live expected time with its delay', () => {
        expect(boardTimeInfo(base)).toEqual({ kind: 'expected', expectedLabel: 'Exp 15:22', delayed: true, showDelay: true });
    });

    it('shows on-time live trains as expected, not delayed', () => {
        expect(boardTimeInfo({ ...base, expectedTime: '15:10', delayMinutes: 0 })).toMatchObject({ expectedLabel: 'Exp 15:10', delayed: false, showDelay: true });
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
