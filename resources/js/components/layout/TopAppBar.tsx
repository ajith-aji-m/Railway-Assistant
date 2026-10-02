import { Link } from '@inertiajs/react';
import { Icon } from '@/components/ui/Icon';
import { urls } from '@/lib/urls';

export function TopAppBar() {
    return (
        <header className="sticky top-0 z-20 flex w-full items-center justify-between border-b border-outline-variant/40 bg-surface px-margin py-space-sm text-primary shadow-sm">
            <Link href={urls.stations()} className="flex items-center space-x-2.5">
                <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-surface-container-high text-primary">
                    <Icon name="train" className="text-[22px]" />
                </span>
                <h1 className="font-headline-md text-headline-md font-bold tracking-tight text-on-surface">Railway Assistant</h1>
            </Link>
            <Link
                href={urls.settings()}
                aria-label="Settings"
                className="flex h-10 w-10 items-center justify-center rounded-xl text-on-surface-variant transition-all duration-150 hover:bg-surface-container-high active:scale-95"
            >
                <Icon name="settings" className="text-[22px]" />
            </Link>
        </header>
    );
}

/** Centered title bar with back arrow (Search Train / Settings in the Stitch showcase). */
export function TitleBar({ title, backHref }: { title: string; backHref: string }) {
    return (
        <header className="sticky top-0 z-20 grid w-full grid-cols-[40px_1fr_40px] items-center border-b border-outline-variant/40 bg-surface px-margin py-space-sm shadow-sm">
            <Link
                href={backHref}
                aria-label="Back"
                className="flex h-10 w-10 items-center justify-center rounded-xl text-on-surface transition-all duration-150 hover:bg-surface-container-high active:scale-95"
            >
                <Icon name="arrow_back" className="text-[22px]" />
            </Link>
            <h1 className="text-center font-headline-md text-headline-md font-bold tracking-tight text-on-surface">{title}</h1>
            <span />
        </header>
    );
}
