import { describe, expect, it } from 'vitest';
import { formatDateTime, timeOf, to12h } from './format';

describe('to12h — the one user-facing time format (h:mm A)', () => {
    it.each([
        ['09:05', '9:05 AM'], // morning, single-digit hour
        ['11:59', '11:59 AM'], // double-digit morning hour
        ['13:30', '1:30 PM'], // afternoon
        ['18:45', '6:45 PM'], // evening
        ['23:05', '11:05 PM'],
        ['00:15', '12:15 AM'], // midnight hour
        ['00:00', '12:00 AM'], // midnight
        ['12:00', '12:00 PM'], // noon
        ['12:30', '12:30 PM'],
        ['10:42:12', '10:42 AM'], // seconds are ignored
    ])('%s → %s', (input, expected) => {
        expect(to12h(input)).toBe(expected);
    });

    it('always includes AM/PM and never a zero-padded hour', () => {
        for (let h = 0; h < 24; h++) {
            const out = to12h(`${String(h).padStart(2, '0')}:07`);
            expect(out).toMatch(/^([1-9]|1[0-2]):07 (AM|PM)$/);
        }
    });

    it('shows a placeholder for missing or malformed times', () => {
        expect(to12h(null)).toBe('--');
        expect(to12h(undefined)).toBe('--');
        expect(to12h('')).toBe('--');
        expect(to12h('soon')).toBe('--');
    });
});

describe('ISO timestamps are shown in station-local time', () => {
    it('reads the IST wall-clock time sent by the server, whatever the viewer timezone', () => {
        // RailRadar and the mock clock both send +05:30 (Asia/Kolkata) timestamps.
        expect(to12h(timeOf('2026-10-02T14:05:00+05:30'))).toBe('2:05 PM');
        expect(to12h(timeOf('2026-10-02T00:20:00+05:30'))).toBe('12:20 AM');
        expect(formatDateTime('2026-10-02T10:42:12+05:30')).toBe('02 Oct 2026, 10:42 AM');
        expect(formatDateTime('2026-10-02T12:00:00+05:30')).toBe('02 Oct 2026, 12:00 PM');
    });
});
