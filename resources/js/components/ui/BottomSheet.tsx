import { useEffect, type ReactNode } from 'react';
import { Icon } from './Icon';

interface BottomSheetProps {
    open: boolean;
    onClose: () => void;
    title: string;
    icon?: string;
    children: ReactNode;
}

/** Modal sheet from the "Search Station Manually" flow. */
export function BottomSheet({ open, onClose, title, icon = 'search', children }: BottomSheetProps) {
    useEffect(() => {
        if (!open) return;
        const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [open, onClose]);

    if (!open) return null;

    return (
        <div
            className="fixed inset-0 z-[60] flex items-end justify-center bg-black/40 p-0 backdrop-blur-sm sm:items-center sm:p-4"
            onClick={onClose}
            role="dialog"
            aria-modal="true"
            aria-label={title}
        >
            <div
                className="max-h-[90vh] w-full max-w-md space-y-space-md overflow-y-auto rounded-t-3xl border border-outline-variant/60 bg-surface-container-lowest p-space-lg shadow-xl sm:rounded-2xl"
                onClick={(e) => e.stopPropagation()}
            >
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-2">
                        <Icon name={icon} className="text-primary" />
                        <h4 className="font-headline-md text-headline-md font-bold text-on-surface">{title}</h4>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        aria-label="Close"
                        className="flex h-8 w-8 items-center justify-center rounded-full bg-surface-container text-on-surface-variant hover:bg-surface-container-high"
                    >
                        <Icon name="close" className="text-sm" />
                    </button>
                </div>
                {children}
            </div>
        </div>
    );
}
