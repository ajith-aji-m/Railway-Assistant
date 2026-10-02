import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { AppShell } from '@/components/layout/AppShell';
import { TitleBar } from '@/components/layout/TopAppBar';
import { Icon } from '@/components/ui/Icon';
import { Toggle } from '@/components/ui/Toggle';
import { setLastLocation } from '@/hooks/useLastLocation';
import { useRecentSearches } from '@/hooks/useRecentSearches';
import { useSettings, type DistanceUnit } from '@/hooks/useSettings';
import { urls } from '@/lib/urls';
import type { SharedProps } from '@/types/inertia';

function Row({ icon, title, subtitle, children }: { icon: string; title: string; subtitle?: string; children: ReactNode }) {
    return (
        <div className="flex min-h-[64px] items-center justify-between gap-3 px-4 py-3.5">
            <div className="flex min-w-0 items-center gap-3.5">
                <Icon name={icon} fill className="text-[22px] text-on-surface" />
                <div className="min-w-0">
                    <p className="font-body-lg text-body-lg font-semibold text-on-surface">{title}</p>
                    {subtitle && <p className="font-body-sm text-body-sm text-on-surface-variant">{subtitle}</p>}
                </div>
            </div>
            {children}
        </div>
    );
}

/**
 * Local app preferences only (stored in this browser). No account, no server-side
 * settings, and no data-provider switch — the provider is an environment setting.
 */
export default function Settings() {
    const [settings, update] = useSettings();
    const recent = useRecentSearches();
    const { app, dataSource } = usePage<SharedProps>().props;
    const live = dataSource === 'live';

    return (
        <AppShell title="Settings" header={<TitleBar title="Settings" backHref={urls.stations()} />} nav="settings">
            <main className="flex-1 space-y-space-lg px-margin pt-space-lg">
                <section className="divide-y divide-outline-variant/30 overflow-hidden rounded-2xl border border-outline-variant/40 bg-surface-container-lowest shadow-sm">
                    <Row icon="location_on" title="Location" subtitle="Use current location">
                        <Toggle
                            label="Use current location"
                            checked={settings.useLocation}
                            onChange={(useLocation) => {
                                update({ useLocation });
                                if (!useLocation) setLastLocation(null); // forget the saved position
                            }}
                        />
                    </Row>

                    <Row icon="straighten" title="Distance Unit">
                        <div className="relative">
                            <select
                                aria-label="Distance unit"
                                value={settings.distanceUnit}
                                onChange={(e) => update({ distanceUnit: e.target.value as DistanceUnit })}
                                className="appearance-none rounded-lg border-none bg-transparent bg-none py-1 pr-6 pl-2 text-right font-body-sm text-body-sm text-on-surface-variant focus:ring-2 focus:ring-primary/30"
                            >
                                <option value="km">Kilometers (km)</option>
                                <option value="mi">Miles (mi)</option>
                            </select>
                            <Icon name="expand_more" className="pointer-events-none absolute top-1/2 right-0 -translate-y-1/2 text-[18px] text-on-surface-variant" />
                        </div>
                    </Row>

                    <Row icon="contrast" title="Theme">
                        <span className="font-body-sm text-body-sm text-on-surface-variant">Light</span>
                    </Row>

                    {/* Alerts are not delivered yet, so the control is shown but disabled rather than pretending to work. */}
                    <Row icon="notifications" title="Notifications" subtitle="Train alerts (not available yet)">
                        <Toggle label="Train alerts (not available yet)" checked={false} disabled onChange={() => undefined} />
                    </Row>

                    <Row
                        icon="history"
                        title="Recent Searches"
                        subtitle={recent.items.length > 0 ? `${recent.items.length} saved on this device` : 'None saved on this device'}
                    >
                        <button
                            type="button"
                            onClick={recent.clear}
                            disabled={recent.items.length === 0}
                            className="shrink-0 rounded-lg px-2 py-1 font-label-md text-label-md font-bold text-primary hover:underline disabled:cursor-not-allowed disabled:text-outline disabled:no-underline"
                        >
                            Clear
                        </button>
                    </Row>

                    <Row icon="info" title="About" subtitle={`${app.name} · ${live ? 'Live Data' : 'Demo Data'}`}>
                        <span className="shrink-0 font-body-sm text-body-sm text-on-surface-variant">v{app.version}</span>
                    </Row>
                </section>

                <p className="px-1 font-body-sm text-body-sm text-outline">
                    Settings are saved on this device. {live ? 'Live railway data by RailRadar. ' : 'Showing a built-in demo timetable. '}Map data © OpenStreetMap
                    contributors.
                </p>
            </main>
        </AppShell>
    );
}
