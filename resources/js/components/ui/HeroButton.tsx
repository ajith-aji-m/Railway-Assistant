import { useState } from 'react';
import { cn } from '@/lib/format';
import { Icon } from './Icon';

interface HeroButtonProps {
    icon: string;
    label: string;
    onClick?: () => void;
    /** Uses the Web Share API, falling back to copying the URL. */
    share?: { title: string; text?: string };
    active?: boolean;
}

/** Round translucent button over photo headers (back / share / favourite). */
export function HeroButton({ icon, label, onClick, share, active }: HeroButtonProps) {
    const [copied, setCopied] = useState(false);

    const handleClick = async () => {
        if (!share) return onClick?.();
        const data = { ...share, url: window.location.href };
        try {
            if (navigator.share) await navigator.share(data);
            else {
                await navigator.clipboard.writeText(data.url);
                setCopied(true);
                window.setTimeout(() => setCopied(false), 1500);
            }
        } catch {
            // user dismissed the share sheet
        }
    };

    return (
        <button
            type="button"
            aria-label={copied ? 'Link copied' : label}
            title={copied ? 'Link copied' : label}
            onClick={handleClick}
            className={cn(
                'flex h-9 w-9 items-center justify-center rounded-full bg-surface-container-lowest/80 text-on-surface shadow-sm backdrop-blur-md transition-transform duration-150 hover:bg-surface-container-lowest active:scale-95',
                active && 'text-primary',
            )}
        >
            <Icon name={copied ? 'check' : icon} fill={active} className="text-[20px]" />
        </button>
    );
}
