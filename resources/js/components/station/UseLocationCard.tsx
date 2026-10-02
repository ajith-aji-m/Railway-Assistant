import { Icon } from '@/components/ui/Icon';

export function UseLocationCard({ onClick }: { onClick: () => void }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className="group relative flex w-full cursor-pointer items-center justify-between rounded-2xl border border-outline-variant/40 bg-surface-container-lowest p-4 text-left shadow-sm transition-all duration-200 hover:shadow-md active:scale-[0.99]"
        >
            <div className="flex items-center space-x-3.5">
                <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-surface-container-high text-primary transition-colors duration-200 group-hover:bg-primary group-hover:text-on-primary">
                    <Icon name="near_me" className="text-[24px]" />
                </div>
                <div className="flex flex-col">
                    <span className="font-headline-md text-headline-md text-on-surface transition-colors group-hover:text-primary">Use Current Location</span>
                    <span className="font-body-sm text-body-sm text-on-surface-variant">Find nearest railway station</span>
                </div>
            </div>
            <div className="flex items-center pl-2 text-outline transition-colors group-hover:text-primary">
                <Icon name="chevron_right" className="text-[22px]" />
            </div>
        </button>
    );
}

export function OrDivider() {
    return (
        <div className="relative flex items-center py-1">
            <div className="grow border-t border-outline-variant/40" />
            <span className="mx-4 shrink font-label-sm text-label-sm font-bold tracking-wider text-outline uppercase">OR</span>
            <div className="grow border-t border-outline-variant/40" />
        </div>
    );
}
