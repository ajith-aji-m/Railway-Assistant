import type { DistanceUnit } from '@/hooks/useSettings';

const KM_PER_MILE = 1.609344;

export function formatDistance(km: number, unit: DistanceUnit = 'km'): string {
    const value = unit === 'mi' ? km / KM_PER_MILE : km;
    return `${value.toFixed(value < 100 ? 1 : 0)} ${unit}`;
}

/**
 * The one formatter for user-facing railway times ("h:mm A"):
 * "13:05" → "1:05 PM", "00:15" → "12:15 AM", "12:00" → "12:00 PM".
 * Input is the backend's 24-hour "HH:MM" (station-local); data values are never changed.
 */
export function to12h(time: string | null | undefined): string {
    const match = time ? /^(\d{1,2}):(\d{2})/.exec(time) : null;
    if (!match) return '--';
    const h = Number(match[1]);
    const suffix = h >= 12 ? 'PM' : 'AM';
    const hour = h % 12 === 0 ? 12 : h % 12;
    return `${hour}:${match[2]} ${suffix}`;
}

/** Signed delay label used in pills: "+7m", "On time". */
export function delayShort(minutes: number): string {
    return minutes > 0 ? `+${minutes}m` : 'On time';
}

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/**
 * Railway times are local to the station, so read ISO strings literally
 * (ignore the viewer's timezone): "2026-10-02T10:35:00+05:30" → parts.
 */
function parts(iso: string) {
    const [date, time = '00:00'] = iso.split('T');
    const [y, mo, d] = date.split('-').map(Number);
    const [h, mi] = time.slice(0, 5).split(':').map(Number);
    return { y, mo, d, h, mi };
}

/** "02 Oct 2026" */
export function formatDate(iso: string): string {
    const { y, mo, d } = parts(iso);
    return `${String(d).padStart(2, '0')} ${MONTHS[mo - 1]} ${y}`;
}

/** "10:35" (24-hour, station-local) from an ISO datetime; pass to to12h() for display. */
export function timeOf(iso: string): string {
    const { h, mi } = parts(iso);
    return `${String(h).padStart(2, '0')}:${String(mi).padStart(2, '0')}`;
}

/** "02 Oct 2026, 10:35 AM" (station-local, whatever the viewer's timezone) */
export function formatDateTime(iso: string): string {
    return `${formatDate(iso)}, ${to12h(timeOf(iso))}`;
}

/** Minutes from the ISO moment until an "HH:MM" time (wraps past midnight). */
export function minutesUntil(fromIso: string, time: string): number {
    const { h, mi } = parts(fromIso);
    const [th, tm] = time.split(':').map(Number);
    let diff = th * 60 + tm - (h * 60 + mi);
    if (diff < -12 * 60) diff += 24 * 60;
    return diff;
}

/** 135 → "2h 15m" */
export function formatDuration(minutes: number): string {
    if (minutes <= 0) return 'now';
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;
    return h > 0 ? `${h}h ${m}m` : `${m}m`;
}

export function cn(...classes: Array<string | false | null | undefined>): string {
    return classes.filter(Boolean).join(' ');
}
