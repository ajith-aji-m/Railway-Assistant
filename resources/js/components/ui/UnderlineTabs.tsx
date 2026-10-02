import { Link } from '@inertiajs/react';
import { cn } from '@/lib/format';

export interface UnderlineTab {
    key: string;
    label: string;
    href?: string; // navigates instead of switching
}

/** Overview / Route / Live Map tabs on the train details screen. */
export function UnderlineTabs({ tabs, active, onChange }: { tabs: UnderlineTab[]; active: string; onChange: (key: string) => void }) {
    const cls = (isActive: boolean) =>
        cn(
            'px-3 pb-2.5 font-label-lg text-label-lg transition-all',
            isActive ? 'border-b-2 border-primary font-bold text-primary' : 'font-medium text-on-surface-variant hover:text-on-surface',
        );

    return (
        <nav aria-label="Detail Sections" className="mt-4 flex items-center gap-2 border-b border-outline-variant/20 pt-1">
            {tabs.map((tab) =>
                tab.href ? (
                    <Link key={tab.key} href={tab.href} className={cls(false)}>
                        {tab.label}
                    </Link>
                ) : (
                    <button key={tab.key} type="button" onClick={() => onChange(tab.key)} className={cls(tab.key === active)} aria-current={tab.key === active}>
                        {tab.label}
                    </button>
                ),
            )}
        </nav>
    );
}
