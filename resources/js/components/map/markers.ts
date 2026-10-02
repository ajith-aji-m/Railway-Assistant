import L from 'leaflet';
import { to12h } from '@/lib/format';
import type { StopStatus } from '@/types/railway';

// Leaflet DivIcons rendered from small HTML templates using the Stitch classes.

const esc = (s: string) => s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]!);

export type StopRole = 'origin' | 'passed' | 'next' | 'other';

export function stopNodeIcon(stop: StopStatus, role: StopRole) {
    const departed = stop.state === 'departed' || stop.state === 'current';
    const cls =
        role === 'next'
            ? 'w-3.5 h-3.5 bg-white border-[3px] border-primary-container'
            : departed
              ? 'w-3 h-3 bg-primary-container border-2 border-white'
              : 'w-3.5 h-3.5 bg-white border-[3px] border-outline';
    return L.divIcon({
        className: 'rail-div-icon',
        html: `<span class="block rounded-full shadow ${cls}"></span>`,
        iconSize: [14, 14],
        iconAnchor: [7, 7],
    });
}

export function stopLabelIcon(stop: StopStatus, role: StopRole, extra: { nextKm?: number | null; gpsLost?: boolean } = {}) {
    const name = esc(stop.station.name);
    let html: string;

    if (role === 'next') {
        const km = extra.nextKm != null ? `Next (${Math.round(extra.nextKm)} km) • ` : '';
        html = `<div class="flex flex-col items-start bg-surface-container-lowest/95 backdrop-blur-sm px-2 py-1 rounded shadow-sm border border-primary/30 whitespace-nowrap">
            <div class="flex items-center gap-1"><span class="w-1.5 h-1.5 rounded-full bg-primary animate-pulse"></span>
            <span class="font-label-sm text-label-sm text-primary font-bold">${name}</span></div>
            <span class="font-label-sm text-[10px] text-outline">${km}${to12h(stop.expectedArrival)}</span></div>`;
    } else if (role === 'passed') {
        html = `<div class="flex flex-col items-start bg-surface-container-lowest/95 backdrop-blur-sm px-2 py-1 rounded shadow-sm border border-outline-variant/40 whitespace-nowrap">
            <div class="flex items-center gap-1"><span class="w-1.5 h-1.5 rounded-full bg-secondary"></span>
            <span class="font-label-sm text-label-sm text-on-surface font-bold">${name}</span></div>
            <span class="font-label-sm text-[10px] text-secondary font-medium">Passed ${to12h(stop.expectedDeparture ?? stop.expectedArrival)}</span></div>`;
    } else if (role === 'origin') {
        html = `<div class="flex flex-col items-start bg-surface-container-lowest/90 backdrop-blur-sm px-2 py-1 rounded shadow-sm border border-outline-variant/40 whitespace-nowrap">
            <span class="font-label-sm text-label-sm text-on-surface font-bold">${name}</span>
            <span class="font-label-sm text-[10px] text-outline">${esc(stop.station.code)} • Dep ${to12h(stop.scheduledDeparture)}</span></div>`;
    } else {
        html = `<div class="bg-surface-container-lowest/90 backdrop-blur-sm px-2 py-0.5 rounded shadow-sm border border-outline-variant/40 whitespace-nowrap">
            <span class="font-label-sm text-label-sm text-on-surface font-semibold">${name}</span></div>`;
    }

    return L.divIcon({ className: 'rail-div-icon', html, iconSize: undefined, iconAnchor: [-10, 12] });
}

export function trainIcon({ tooltip, estimated }: { tooltip: string | null; estimated: boolean }) {
    const pill = tooltip
        ? `<div class="mb-1.5 flex items-center gap-1 bg-on-surface text-surface-container-lowest px-2.5 py-1 rounded-full shadow-md">
            <span class="material-symbols-outlined text-[14px] text-tertiary-fixed-dim">navigation</span>
            <span class="font-label-sm text-[11px] font-semibold whitespace-nowrap">${esc(tooltip)}</span></div>`
        : '';

    const pin = estimated
        ? `<div class="relative flex items-center justify-center">
            <div class="absolute w-12 h-12 rounded-full border-2 border-dashed border-amber-500/70 animate-[spin_8s_linear_infinite]"></div>
            <div class="relative z-10 w-9 h-9 rounded-full bg-amber-600 text-white flex items-center justify-center shadow-lg border-2 border-surface-container-lowest">
              <span class="material-symbols-outlined icon-fill text-[20px]">train</span></div></div>
           <span class="mt-1 px-1.5 py-0.5 rounded bg-inverse-surface text-inverse-on-surface font-label-sm text-[10px] font-bold tracking-wider">ESTIMATED</span>`
        : `<div class="relative flex items-center justify-center">
            <div class="absolute w-12 h-12 rounded-full bg-tertiary/20 animate-subtle-pulse"></div>
            <div class="relative z-10 w-9 h-9 rounded-full bg-tertiary text-on-tertiary flex items-center justify-center shadow-lg border-2 border-surface-container-lowest">
              <span class="material-symbols-outlined icon-fill text-[20px]">train</span></div></div>`;

    // Anchor the pin centre on the position: tooltip (≈28px) sits above the 36px pin.
    const pinCenterY = (tooltip ? 32 : 0) + 18;
    return L.divIcon({
        className: 'rail-div-icon',
        html: `<div class="flex flex-col items-center" style="width:240px">${pill}${pin}</div>`,
        iconSize: [240, 0],
        iconAnchor: [120, pinCenterY],
    });
}
