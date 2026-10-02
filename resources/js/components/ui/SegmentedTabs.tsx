import { cn } from '@/lib/format';
import { Icon } from './Icon';

interface Tab<T extends string> {
    value: T;
    label: string;
    icon?: string;
}

/** Arrivals / Departures pill switcher. */
export function SegmentedTabs<T extends string>({ tabs, value, onChange }: { tabs: Tab<T>[]; value: T; onChange: (value: T) => void }) {
    return (
        <div role="tablist" className="relative grid grid-cols-2 rounded-xl bg-surface-container-low p-1 text-center">
            {tabs.map((tab) => {
                const active = tab.value === value;
                return (
                    <button
                        key={tab.value}
                        type="button"
                        role="tab"
                        aria-selected={active}
                        onClick={() => !active && onChange(tab.value)}
                        className={cn(
                            'flex items-center justify-center space-x-1.5 rounded-lg py-2 font-label-lg text-label-lg transition-all duration-200',
                            active ? 'bg-surface-container-lowest font-bold text-primary shadow-sm' : 'font-semibold text-on-surface-variant hover:text-on-surface',
                        )}
                    >
                        {tab.icon && <Icon name={tab.icon} className="text-[18px]" />}
                        <span>{tab.label}</span>
                    </button>
                );
            })}
        </div>
    );
}
