import { Icon } from '@/components/ui/Icon';
import { HeroButton } from '@/components/ui/HeroButton';
import { useSettings } from '@/hooks/useSettings';
import { formatDistance } from '@/lib/format';
import type { StationDetail } from '@/types/railway';

/** Photo header from the Stitch station dashboard. */
export function StationHero({
    station,
    distanceKm,
    onBack,
    onChangeStation,
}: {
    station: StationDetail;
    distanceKm: number | null;
    onBack: () => void;
    onChangeStation?: () => void;
}) {
    const [{ distanceUnit }] = useSettings();
    const place = [station.city, station.state].filter(Boolean).join(', ');

    return (
        <section className="relative h-56 w-full overflow-hidden bg-surface-container-highest">
            {station.image ? (
                <img src={station.image} alt={`${station.name} railway station`} className="h-full w-full object-cover brightness-[0.82]" />
            ) : (
                <div className="flex h-full w-full items-center justify-center bg-gradient-to-br from-on-primary-fixed-variant via-primary to-primary-container">
                    <Icon name="train" className="text-[160px] text-white/10" />
                </div>
            )}
            <div className="absolute inset-0 bg-gradient-to-t from-on-surface/90 via-on-surface/40 to-transparent" />

            <div className="absolute top-4 right-0 left-0 z-20 flex items-center justify-between px-margin">
                <HeroButton icon="arrow_back" label="Back" onClick={onBack} />
                <div className="flex items-center gap-2">
                    {onChangeStation && <HeroButton icon="swap_horiz" label="Change Station" onClick={onChangeStation} />}
                    <HeroButton icon="share" label="Share" share={{ title: `${station.name} (${station.code})` }} />
                </div>
            </div>

            <div className="absolute right-0 bottom-3 left-0 z-20 flex items-end justify-between px-margin">
                <div>
                    <h1 className="font-headline-lg text-headline-lg leading-tight font-bold tracking-tight text-surface-container-lowest drop-shadow-sm">
                        {station.name} ({station.code})
                    </h1>
                    {place && (
                        <p className="mt-0.5 flex items-center font-body-sm text-body-sm text-surface-container-low/90">
                            <Icon name="location_on" className="mr-1 text-[15px] text-surface-container-high" />
                            {place}
                        </p>
                    )}
                </div>
                {distanceKm !== null && (
                    <div className="flex shrink-0 items-center space-x-1 rounded-full border border-outline-variant/40 bg-surface-container-lowest/90 px-2.5 py-1 shadow-sm backdrop-blur-sm">
                        <Icon name="near_me" className="text-[14px] text-primary" />
                        <span className="font-label-sm text-label-sm font-bold text-on-surface">{formatDistance(distanceKm, distanceUnit)}</span>
                    </div>
                )}
            </div>
        </section>
    );
}
