import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/format';
import { BottomNav, type NavTab } from './BottomNav';
import { TopAppBar } from './TopAppBar';

interface AppShellProps {
    title?: string;
    /** Replaces the default "Railway Assistant" top bar; pass `null` to hide it. */
    header?: ReactNode | null;
    nav?: NavTab | false;
    /** Reserve space at the bottom for the fixed nav (off for full-screen pages like the map). */
    padForNav?: boolean;
    className?: string;
    children: ReactNode;
}

/**
 * Mobile-width column from the Stitch export (max-w-md, centered on wider screens)
 * with the shared top app bar and bottom navigation.
 */
export function AppShell({ title, header, nav, padForNav = true, className, children }: AppShellProps) {
    return (
        <>
            {title && <Head title={title} />}
            <div className="flex min-h-dvh justify-center bg-background">
                <div
                    className={cn(
                        'relative flex min-h-dvh w-full max-w-md flex-col border-x border-outline-variant/30 bg-surface shadow-2xl',
                        nav !== false && padForNav && 'pb-28',
                        className,
                    )}
                >
                    {header === undefined ? <TopAppBar /> : header}
                    {children}
                </div>
            </div>
            {nav !== false && <BottomNav active={nav} />}
        </>
    );
}
