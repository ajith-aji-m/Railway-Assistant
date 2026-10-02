import { cn } from '@/lib/format';

export function Toggle({
    checked,
    onChange,
    label,
    disabled = false,
}: {
    checked: boolean;
    onChange: (checked: boolean) => void;
    label: string;
    disabled?: boolean;
}) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={checked}
            aria-label={label}
            disabled={disabled}
            onClick={() => onChange(!checked)}
            className={cn(
                'relative inline-flex h-7 w-12 shrink-0 items-center rounded-full transition-colors duration-200 focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50',
                checked ? 'bg-primary-container' : 'bg-outline-variant',
            )}
        >
            <span className={cn('inline-block h-5 w-5 rounded-full bg-white shadow-sm transition-transform duration-200', checked ? 'translate-x-6' : 'translate-x-1')} />
        </button>
    );
}
