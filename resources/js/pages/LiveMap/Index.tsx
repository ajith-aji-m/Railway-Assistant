import { AppShell } from '@/components/layout/AppShell';
import { ButtonLink } from '@/components/ui/Button';
import { StateCard } from '@/components/ui/StateCard';
import { urls } from '@/lib/urls';

/** "Live Map" tab when no train has been opened yet. */
export default function LiveMapIndex() {
    return (
        <AppShell title="Live Map" nav="map">
            <main className="flex flex-1 flex-col px-margin pt-space-lg">
                <StateCard icon="map" title="No Train Selected" description="Search for a train by number or name to follow its live position on the map.">
                    <ButtonLink href={urls.trainSearch()} icon="search">
                        Search Train
                    </ButtonLink>
                    <ButtonLink href={urls.stations()} variant="soft" icon="near_me">
                        Browse Nearby Stations
                    </ButtonLink>
                </StateCard>
            </main>
        </AppShell>
    );
}
