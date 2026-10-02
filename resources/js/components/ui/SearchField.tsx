import { forwardRef, type InputHTMLAttributes } from 'react';
import { cn } from '@/lib/format';
import { Icon } from './Icon';

interface SearchFieldProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'onChange'> {
    value: string;
    onChange: (value: string) => void;
    icon?: string;
    loading?: boolean;
}

/** Station search input (48px, leading magnifier) from the location screen. */
export const SearchField = forwardRef<HTMLInputElement, SearchFieldProps>(function SearchField(
    { value, onChange, icon = 'search', loading, className, ...props },
    ref,
) {
    return (
        <div className="relative flex w-full items-center">
            <Icon name={icon} className="pointer-events-none absolute left-3.5 text-[20px] text-outline" />
            <input
                ref={ref}
                type="search"
                value={value}
                onChange={(e) => onChange(e.target.value)}
                className={cn(
                    'h-12 w-full rounded-xl border border-outline-variant/50 bg-surface-container-lowest pr-11 pl-11 font-body-md text-body-md text-on-surface shadow-sm transition-all duration-150 placeholder:text-outline focus:border-primary focus:ring-2 focus:ring-primary focus:outline-none [&::-webkit-search-cancel-button]:hidden',
                    className,
                )}
                {...props}
            />
            {loading ? (
                <Icon name="progress_activity" className="absolute right-3.5 animate-spin text-[20px] text-primary" />
            ) : (
                value && (
                    <button
                        type="button"
                        aria-label="Clear search"
                        onClick={() => onChange('')}
                        className="absolute right-3 flex h-6 w-6 items-center justify-center rounded-full bg-surface-container-high text-on-surface"
                    >
                        <Icon name="close" className="text-[16px]" />
                    </button>
                )
            )}
        </div>
    );
});
