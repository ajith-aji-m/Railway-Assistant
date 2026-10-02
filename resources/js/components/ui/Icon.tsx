import { cn } from '@/lib/format';

interface IconProps {
    name: string;
    fill?: boolean;
    className?: string;
}

/** Material Symbols Outlined glyph (self-hosted). Size via text-[Npx] classes. */
export function Icon({ name, fill = false, className }: IconProps) {
    return (
        <span aria-hidden="true" className={cn('material-symbols-outlined', fill && 'icon-fill', className)}>
            {name}
        </span>
    );
}
