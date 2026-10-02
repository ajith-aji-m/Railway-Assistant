import { Link } from '@inertiajs/react';
import { Icon } from '@/components/ui/Icon';
import { useLastTrain } from '@/hooks/useLastTrain';
import { cn } from '@/lib/format';
import { urls } from '@/lib/urls';

export type NavTab = 'stations' | 'search' | 'map' | 'settings';

export function BottomNav({ active }: { active?: NavTab }) {
    const lastTrain = useLastTrain();

    const items: { key: NavTab; label: string; icon: string; href: string }[] = [
        { key: 'stations', label: 'Stations', icon: 'near_me', href: urls.stations() },
        { key: 'search', label: 'Search', icon: 'search', href: urls.trainSearch() },
        { key: 'map', label: 'Live Map', icon: 'map', href: lastTrain ? urls.trainMap(lastTrain) : urls.map() },
        { key: 'settings', label: 'Settings', icon: 'settings', href: urls.settings() },
    ];

    return (
        <nav className="fixed inset-x-0 bottom-0 z-50 border-t border-outline-variant/40 bg-surface-container-lowest pb-[env(safe-area-inset-bottom)] shadow-md">
            <div className="mx-auto flex max-w-md items-center justify-around px-margin py-space-sm">
                {items.map((item) => {
                    const isActive = item.key === active;
                    return (
                        <Link
                            key={item.key}
                            href={item.href}
                            aria-current={isActive ? 'page' : undefined}
                            className={cn(
                                'flex flex-col items-center justify-center px-3 py-1 font-label-md text-label-md transition-colors duration-150 active:scale-95',
                                isActive ? 'text-primary' : 'text-on-surface-variant hover:text-primary',
                            )}
                        >
                            <Icon name={item.icon} fill={isActive} className="mb-0.5 text-[22px]" />
                            <span className={isActive ? 'font-bold' : 'font-medium'}>{item.label}</span>
                        </Link>
                    );
                })}
            </div>
        </nav>
    );
}
