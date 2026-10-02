import { Link } from '@inertiajs/react';
import { Icon } from '@/components/ui/Icon';
import { urls } from '@/lib/urls';

const cardClass =
    'group relative flex w-full cursor-pointer items-center justify-between rounded-2xl border border-outline-variant/40 bg-surface-container-lowest p-4 text-left shadow-sm transition-all duration-200 hover:shadow-md active:scale-[0.99]';

function CardBody({ icon, title, subtitle }: { icon: string; title: string; subtitle: string }) {
    return (
        <>
            <div className="flex items-center space-x-3.5">
                <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-surface-container-high text-primary transition-colors duration-200 group-hover:bg-primary group-hover:text-on-primary">
                    <Icon name={icon} className="text-[24px]" />
                </div>
                <div className="flex flex-col">
                    <span className="font-headline-md text-headline-md text-on-surface transition-colors group-hover:text-primary">{title}</span>
                    <span className="font-body-sm text-body-sm text-on-surface-variant">{subtitle}</span>
                </div>
            </div>
            <div className="flex items-center pl-2 text-outline transition-colors group-hover:text-primary">
                <Icon name="chevron_right" className="text-[22px]" />
            </div>
        </>
    );
}

export function UseLocationCard({ onClick }: { onClick: () => void }) {
    return (
        <button type="button" onClick={onClick} className={cardClass}>
            <CardBody icon="near_me" title="Use Current Location" subtitle="Find nearest railway station" />
        </button>
    );
}

/** Same card style, linking to Train Search (track one specific train by number). */
export function TrainSearchCard() {
    return (
        <Link href={urls.trainSearch()} className={cardClass}>
            <CardBody icon="directions_transit" title="Track a Train" subtitle="Search by train number" />
        </Link>
    );
}

export function OrDivider() {
    return (
        <div className="relative flex items-center py-1">
            <div className="grow border-t border-outline-variant/40" />
            <span className="mx-4 shrink font-label-sm text-label-sm font-bold tracking-wider text-outline uppercase">OR</span>
            <div className="grow border-t border-outline-variant/40" />
        </div>
    );
}
