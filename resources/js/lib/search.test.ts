import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createSearchScheduler, normalizeQuery, queryState } from './search';
import { urls } from './urls';

describe('search query helpers', () => {
    it('normalizes whitespace', () => {
        expect(normalizeQuery('  MGR   Chennai  ')).toBe('MGR Chennai');
    });

    it('classifies empty / short / ready queries', () => {
        expect(queryState('', 2)).toBe('empty');
        expect(queryState('   ', 2)).toBe('empty');
        expect(queryState('c', 2)).toBe('short');
        expect(queryState('ch', 2)).toBe('ready');
    });

    it('opens the existing Station Dashboard route for a selected result', () => {
        expect(urls.station('MAS')).toBe('/stations/MAS');
    });
});

describe('createSearchScheduler', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => vi.useRealTimers());

    const setup = () => {
        const onSearch = vi.fn();
        return { onSearch, scheduler: createSearchScheduler({ delayMs: 400, minLength: 2, onSearch }) };
    };

    it('debounces typing into a single request', () => {
        const { onSearch, scheduler } = setup();
        for (const partial of ['Ch', 'Che', 'Chen', 'Chenn', 'Chennai']) scheduler.update(partial);

        vi.advanceTimersByTime(399);
        expect(onSearch).not.toHaveBeenCalled();
        vi.advanceTimersByTime(1);
        expect(onSearch).toHaveBeenCalledTimes(1);
        expect(onSearch).toHaveBeenCalledWith('Chennai');
    });

    it('does not search short queries', () => {
        const { onSearch, scheduler } = setup();
        expect(scheduler.update('C')).toBe('short');
        vi.runAllTimers();
        expect(onSearch).not.toHaveBeenCalled();
    });

    it('cancels a pending search when the query becomes too short', () => {
        const { onSearch, scheduler } = setup();
        scheduler.update('Ch');
        scheduler.update('C');
        vi.runAllTimers();
        expect(onSearch).not.toHaveBeenCalled();
    });

    it('clears results for an empty query without a provider search', () => {
        const { onSearch, scheduler } = setup();
        scheduler.prime('Chennai');
        expect(scheduler.update('')).toBe('empty');
        vi.runAllTimers();
        expect(onSearch).toHaveBeenCalledWith('');
    });

    it('skips duplicate queries (case/whitespace-insensitive)', () => {
        const { onSearch, scheduler } = setup();
        scheduler.update('Salem');
        vi.runAllTimers();
        scheduler.update('  salem ');
        vi.runAllTimers();
        expect(onSearch).toHaveBeenCalledTimes(1);
    });

    it('does not resend the query the page was loaded with', () => {
        const { onSearch, scheduler } = setup();
        scheduler.prime('MAS');
        scheduler.update('MAS');
        vi.runAllTimers();
        expect(onSearch).not.toHaveBeenCalled();
    });

    it('resends after reset (retry)', () => {
        const { onSearch, scheduler } = setup();
        scheduler.update('Salem');
        vi.runAllTimers();
        scheduler.reset();
        scheduler.update('Salem');
        vi.runAllTimers();
        expect(onSearch).toHaveBeenCalledTimes(2);
    });
});
