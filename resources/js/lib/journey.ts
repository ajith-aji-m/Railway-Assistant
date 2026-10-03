import { boardTimeInfo } from '@/lib/board';
import { queryState } from '@/lib/search';
import { to12h } from '@/lib/format';
import type { BoardPhase, JourneyOption, LatLng, StationSummary } from '@/types/railway';

export type JourneyField = 'from' | 'to';

export interface JourneySelection {
    from: StationSummary | null;
    to: StationSummary | null;
}

/** Find Trains is enabled only once two different stations are chosen. */
export function canFindTrains({ from, to }: JourneySelection): boolean {
    return from !== null && to !== null && from.code !== to.code;
}

export function swapStations({ from, to }: JourneySelection): JourneySelection {
    return { from: to, to: from };
}

/**
 * Prefill From with the nearest station (the backend returns nearby stations nearest
 * first) — only while the user has not chosen one.
 */
export function suggestedFrom(current: StationSummary | null, nearby: StationSummary[] | null): StationSummary | null {
    return current ?? nearby?.[0] ?? null;
}

export type PanelState =
    | { kind: 'nearby'; stations: StationSummary[] }
    | { kind: 'results'; stations: StationSummary[] }
    | { kind: 'hint'; message: string }
    | { kind: 'searching' }
    | { kind: 'empty'; message: string }
    | { kind: 'error'; message: string };

export const FROM_HINT = 'Search for your starting station';
export const TO_HINT = 'Search for your destination';
export const NO_STATIONS = 'No stations found';
export const NO_TRAINS = 'No trains found for this route';

/**
 * What the open From / To picker shows.
 * From: nearby stations (nearest first) until the user types; without a location, a search prompt.
 * To: never nearby stations — a search prompt until the user types, then matching stations.
 */
export function pickerPanel(
    field: JourneyField,
    input: {
        text: string;
        minLength: number;
        /** The query the current results belong to. */
        query: string;
        results: StationSummary[] | null;
        error: string | null;
        nearby: StationSummary[] | null;
        location: LatLng | null;
    },
): PanelState {
    const state = queryState(input.text, input.minLength);

    if (state !== 'ready') {
        if (field === 'from' && input.location && input.nearby && input.nearby.length > 0) {
            return { kind: 'nearby', stations: input.nearby };
        }
        return { kind: 'hint', message: field === 'from' ? FROM_HINT : TO_HINT };
    }
    if (input.query.toLowerCase() !== input.text.trim().replace(/\s+/g, ' ').toLowerCase() || (input.results === null && input.error === null)) {
        return { kind: 'searching' };
    }
    if (input.error) return { kind: 'error', message: input.error };
    if (!input.results || input.results.length === 0) return { kind: 'empty', message: NO_STATIONS };
    return { kind: 'results', stations: input.results };
}

export interface JourneyTimes {
    departs: string;
    /** "Exp 2:20 PM" when backed by live data, otherwise null. */
    expectedDeparture: string | null;
    arrives: string | null;
    expectedArrival: string | null;
    /** "+1 day" when the train reaches To on a later day. */
    arrivalDay: string | null;
    delayed: boolean;
    showDelay: boolean;
}

/** Times shown on a journey result. All values come from the backend; nothing is computed here. */
export function journeyTimes(option: JourneyOption): JourneyTimes {
    const dep = boardTimeInfo(option.departure);
    const cancelled = option.departure.status === 'cancelled';

    return {
        departs: to12h(option.departure.scheduledTime),
        expectedDeparture: dep.expectedLabel,
        arrives: option.arrives ? to12h(option.arrives) : null,
        expectedArrival: !cancelled && option.departure.isLive && option.expectedArrival && option.expectedArrival !== option.arrives ? `Exp ${to12h(option.expectedArrival)}` : null,
        arrivalDay: option.arrivalDayOffset > 0 ? `+${option.arrivalDayOffset} day${option.arrivalDayOffset > 1 ? 's' : ''}` : null,
        delayed: dep.delayed,
        showDelay: dep.showDelay,
    };
}

export interface JourneyGroup {
    phase: BoardPhase;
    label: string;
    options: JourneyOption[];
}

const PHASES: { phase: BoardPhase; label: string }[] = [
    { phase: 'running', label: 'Running' },
    { phase: 'upcoming', label: 'Upcoming' },
    { phase: 'completed', label: 'Completed' },
];

/** Results grouped Running → Upcoming → Completed (catchable trains first); backend order kept. */
export function groupJourneys(options: JourneyOption[]): JourneyGroup[] {
    return PHASES.map(({ phase, label }) => ({ phase, label, options: options.filter((o) => o.departure.phase === phase) })).filter((g) => g.options.length > 0);
}
