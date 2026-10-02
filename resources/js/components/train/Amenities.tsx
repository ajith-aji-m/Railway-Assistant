import { Icon } from '@/components/ui/Icon';
import { zoneLabel } from '@/lib/live';
import type { TrainDetail } from '@/types/railway';

export function Amenities({ train }: { train: TrainDetail }) {
    const wifi = train.wifiStations?.map((s) => s.code) ?? null;
    const wifiText =
        wifi === null ? 'Unknown' : wifi.length === 0 ? 'Not available' : `At ${wifi.slice(0, 2).join(' & ')}${wifi.length > 2 ? ` +${wifi.length - 2}` : ''}`;

    // positive: true = green, false = muted, null = neutral (also used for unknown values)
    const items = [
        {
            icon: 'restaurant',
            title: 'Pantry Car',
            value: train.hasPantry === null ? 'Unknown' : train.hasPantry ? 'Available' : 'Not available',
            positive: train.hasPantry,
        },
        { icon: 'airline_seat_recline_extra', title: 'Rake Zone', value: train.zone ? zoneLabel(train.zone) : 'Unknown', positive: null },
        { icon: 'wifi', title: 'Station Wi-Fi', value: wifiText, positive: wifi === null ? null : wifi.length > 0 },
    ];

    return (
        <article className="flex items-center justify-around rounded-xl border border-outline-variant/30 bg-surface-container-lowest p-3.5 text-center shadow-sm">
            {items.map((item, i) => (
                <div key={item.title} className="contents">
                    {i > 0 && <div className="h-8 w-px bg-outline-variant/20" />}
                    <div className="flex flex-col items-center">
                        <Icon name={item.icon} className="text-[20px] text-primary" />
                        <span className="mt-1 font-label-sm text-label-sm font-semibold text-on-surface">{item.title}</span>
                        <span
                            className={`font-label-sm text-label-sm font-medium ${item.positive === null ? 'text-on-surface-variant' : item.positive ? 'text-secondary' : 'text-outline'}`}
                        >
                            {item.value}
                        </span>
                    </div>
                </div>
            ))}
        </article>
    );
}
