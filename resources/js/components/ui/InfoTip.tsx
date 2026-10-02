import type { ReactNode } from 'react';
import { Icon } from './Icon';

/** "Quick glance" tip card from the location screen. */
export function InfoTip({ children, icon = 'info' }: { children: ReactNode; icon?: string }) {
    return (
        <section className="flex items-center space-x-3 rounded-2xl border border-outline-variant/30 bg-surface-container-low p-3.5">
            <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-secondary-container/40 text-on-secondary-container">
                <Icon name={icon} className="text-[18px]" />
            </div>
            <p className="font-body-sm text-body-sm text-on-surface-variant">{children}</p>
        </section>
    );
}
