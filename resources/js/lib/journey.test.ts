import { describe, expect, it } from 'vitest';
import { canFindTrains, FROM_HINT, groupJourneys, journeyTimes, NO_STATIONS, NO_TRAINS, pickerPanel, suggestedFrom, swapStations, TO_HINT } from './journey';
import { urls } from './urls';
import type { BoardEntry, JourneyOption, StationSummary } from '@/types/railway';

const station = (code: string, name: string, distanceKm: number | null = null): StationSummary => ({
    code,
    name,
    city: null,
    state: 'Tamil Nadu',
    lat: null,
    lng: null,
    distanceKm,
});

const PYD = station('PYD', 'Palliyadi', 0.2);
const ERL = station('ERL', 'Eraniel', 10.5);
const KZT = station('KZT', 'Kulitthurai', 6.3);
const LOCATION = { lat: 8.266, lng: 77.261 };

const entry = (over: Partial<BoardEntry> = {}): BoardEntry => ({
    trainNumber: '06425',
    trainName: 'Trivandrum - Nagercoil Special',
    trainType: 'Passenger',
    from: { code: 'TVC', name: 'Trivandrum Central' },
    to: { code: 'NCJ', name: 'Nagercoil Jn' },
    scheduledTime: '14:15',
    expectedTime: '14:15',
    platform: '1',
    delayMinutes: 0,
    status: 'expected',
    isLive: true,
    phase: 'upcoming',
    ...over,
});

const option = (over: Partial<JourneyOption> = {}, dep: Partial<BoardEntry> = {}): JourneyOption => ({
    departure: entry(dep),
    boarding: { code: 'PYD', name: 'Palliyadi' },
    alighting: { code: 'ERL', name: 'Eraniel' },
    arrives: '14:32',
    expectedArrival: '14:32',
    arrivalDayOffset: 0,
    ...over,
});

const panelInput = {
    text: '',
    minLength: 2,
    query: '',
    results: null,
    error: null,
    nearby: [PYD, KZT, ERL],
    location: LOCATION,
};

describe('From field', () => {
    it('shows nearby stations (nearest first, as sent by the backend) when opened', () => {
        expect(pickerPanel('from', panelInput)).toEqual({ kind: 'nearby', stations: [PYD, KZT, ERL] });
    });

    it('suggests the nearest station until the user chooses one', () => {
        expect(suggestedFrom(null, [PYD, KZT])).toBe(PYD);
        expect(suggestedFrom(ERL, [PYD, KZT])).toBe(ERL);
        expect(suggestedFrom(null, null)).toBeNull();
        expect(suggestedFrom(null, [])).toBeNull();
    });

    it('falls back to a search prompt without a location', () => {
        expect(pickerPanel('from', { ...panelInput, location: null, nearby: null })).toEqual({ kind: 'hint', message: FROM_HINT });
        expect(FROM_HINT).toBe('Search for your starting station');
    });

    it('can be overridden by typing a station name', () => {
        expect(pickerPanel('from', { ...panelInput, text: 'Eraniel', query: 'Eraniel', results: [ERL] })).toEqual({ kind: 'results', stations: [ERL] });
    });
});

describe('To field', () => {
    it('never shows nearby stations before typing', () => {
        expect(pickerPanel('to', panelInput)).toEqual({ kind: 'hint', message: TO_HINT });
        expect(TO_HINT).toBe('Search for your destination');
    });

    it('waits for the minimum length (same rule as station search)', () => {
        expect(pickerPanel('to', { ...panelInput, text: 'E' })).toEqual({ kind: 'hint', message: TO_HINT });
    });

    it('shows matching stations for the typed destination', () => {
        expect(pickerPanel('to', { ...panelInput, text: '  Eraniel ', query: 'Eraniel', results: [ERL] })).toEqual({ kind: 'results', stations: [ERL] });
    });

    it('shows a searching state while results belong to an older query', () => {
        expect(pickerPanel('to', { ...panelInput, text: 'Eraniel', query: 'Era', results: [ERL] })).toEqual({ kind: 'searching' });
        expect(pickerPanel('to', { ...panelInput, text: 'Eraniel', query: 'Eraniel', results: null })).toEqual({ kind: 'searching' });
    });

    it('shows "No stations found" for a destination without matches', () => {
        expect(pickerPanel('to', { ...panelInput, text: 'Kalakkad', query: 'Kalakkad', results: [] })).toEqual({ kind: 'empty', message: NO_STATIONS });
        expect(NO_STATIONS).toBe('No stations found');
    });

    it('shows the provider error', () => {
        expect(pickerPanel('to', { ...panelInput, text: 'Eraniel', query: 'Eraniel', error: 'Service unavailable' })).toEqual({
            kind: 'error',
            message: 'Service unavailable',
        });
    });
});

describe('selection, Find Trains and swap', () => {
    it('enables Find Trains only when two different stations are selected', () => {
        expect(canFindTrains({ from: null, to: null })).toBe(false);
        expect(canFindTrains({ from: PYD, to: null })).toBe(false);
        expect(canFindTrains({ from: null, to: ERL })).toBe(false);
        expect(canFindTrains({ from: PYD, to: PYD })).toBe(false);
        expect(canFindTrains({ from: PYD, to: ERL })).toBe(true);
    });

    it('swaps the selected stations', () => {
        expect(swapStations({ from: PYD, to: ERL })).toEqual({ from: ERL, to: PYD });
        expect(swapStations({ from: PYD, to: null })).toEqual({ from: null, to: PYD });
    });

    it('builds the journey URL with only the selected stations', () => {
        expect(urls.journey()).toBe('/journey');
        expect(urls.journey({ from: 'PYD', to: 'ERL' })).toBe('/journey?from=PYD&to=ERL');
        expect(urls.journey({ from: 'PYD', to: undefined, q: '' })).toBe('/journey?from=PYD');
    });
});

describe('journey results', () => {
    it('shows departure from From and arrival at To', () => {
        const t = journeyTimes(option());
        expect(t.departs).toBe('2:15 PM');
        expect(t.arrives).toBe('2:32 PM');
        expect(t.expectedArrival).toBeNull(); // same as scheduled
        expect(t.arrivalDay).toBeNull();
        expect(t.showDelay).toBe(true);
    });

    it('shows expected times and delay only with live data', () => {
        const live = journeyTimes(option({ expectedArrival: '14:39' }, { expectedTime: '14:22', delayMinutes: 7 }));
        expect(live.expectedDeparture).toBe('Exp 2:22 PM');
        expect(live.expectedArrival).toBe('Exp 2:39 PM');
        expect(live.delayed).toBe(true);

        const timetable = journeyTimes(option({ expectedArrival: null }, { isLive: false, status: 'scheduled', expectedTime: null, delayMinutes: null }));
        expect(timetable.expectedDeparture).toBeNull();
        expect(timetable.expectedArrival).toBeNull();
        expect(timetable.showDelay).toBe(false);
    });

    it('never shows expected times for a cancelled train', () => {
        const t = journeyTimes(option({ expectedArrival: '14:40' }, { status: 'cancelled' }));
        expect(t.expectedDeparture).toBeNull();
        expect(t.expectedArrival).toBeNull();
        expect(t.showDelay).toBe(false);
    });

    it('marks an arrival on a later day and a missing arrival time', () => {
        expect(journeyTimes(option({ arrivalDayOffset: 1 })).arrivalDay).toBe('+1 day');
        expect(journeyTimes(option({ arrivalDayOffset: 2 })).arrivalDay).toBe('+2 days');
        expect(journeyTimes(option({ arrives: null })).arrives).toBeNull();
    });

    it('groups results Running → Upcoming → Completed, keeping backend order', () => {
        const a = option({}, { trainNumber: 'A', phase: 'completed' });
        const b = option({}, { trainNumber: 'B', phase: 'upcoming' });
        const c = option({}, { trainNumber: 'C', phase: 'running' });
        const d = option({}, { trainNumber: 'D', phase: 'upcoming' });

        const groups = groupJourneys([a, b, c, d]);
        expect(groups.map((g) => g.label)).toEqual(['Running', 'Upcoming', 'Completed']);
        expect(groups[1].options.map((o) => o.departure.trainNumber)).toEqual(['B', 'D']);
        expect(groupJourneys([])).toEqual([]);
    });

    it('has the route empty-state message', () => {
        expect(NO_TRAINS).toBe('No trains found for this route');
    });
});
