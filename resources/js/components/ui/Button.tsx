import { Link } from '@inertiajs/react';
import type { ComponentProps, ReactNode } from 'react';
import { cn } from '@/lib/format';
import { Icon } from './Icon';

type Variant = 'primary' | 'soft' | 'neutral' | 'danger';

const base =
    'w-full h-12 font-label-lg text-label-lg font-semibold rounded-xl flex items-center justify-center gap-2 active:scale-[0.98] transition-all duration-150 disabled:opacity-60 disabled:active:scale-100';

const variants: Record<Variant, string> = {
    primary: 'bg-primary hover:bg-[#0b5ed7] text-white shadow-sm',
    soft: 'bg-surface-container hover:bg-surface-container-high text-primary border border-outline-variant/60',
    neutral: 'bg-surface-container hover:bg-surface-container-high text-on-surface border border-outline-variant/60',
    danger: 'bg-tertiary hover:bg-on-tertiary-fixed-variant text-white shadow-sm',
};

interface CommonProps {
    variant?: Variant;
    icon?: string;
    iconFill?: boolean;
    children: ReactNode;
    className?: string;
}

export function Button({ variant = 'primary', icon, iconFill, children, className, ...props }: CommonProps & ComponentProps<'button'>) {
    return (
        <button type="button" className={cn(base, variants[variant], className)} {...props}>
            {icon && <Icon name={icon} fill={iconFill} className="text-lg" />}
            <span>{children}</span>
        </button>
    );
}

export function ButtonLink({ variant = 'primary', icon, iconFill, children, className, href }: CommonProps & { href: string }) {
    return (
        <Link href={href} className={cn(base, variants[variant], className)}>
            {icon && <Icon name={icon} fill={iconFill} className="text-lg" />}
            <span>{children}</span>
        </Link>
    );
}
