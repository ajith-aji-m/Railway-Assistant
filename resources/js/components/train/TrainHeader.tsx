import { RunningPill } from '@/components/train/Badges';
import { HeroButton } from '@/components/ui/HeroButton';
import { Icon } from '@/components/ui/Icon';
import { useTrainFlags } from '@/hooks/useFavourites';
import type { TrainDetail } from '@/types/railway';

/** Locomotive photo banner + identity block from the Stitch train details screen. */
export function TrainHeader({ train, onBack }: { train: TrainDetail; onBack: () => void }) {
    const { favourite, toggleFavourite } = useTrainFlags(train.number);
    const shareData = { title: `${train.number} – ${train.name}`, text: `Live status of ${train.number} ${train.name}` };

    return (
        <>
            <section className="relative h-48 w-full overflow-hidden bg-inverse-surface">
                {train.image && <img src={train.image} alt={`${train.name} locomotive`} className="h-full w-full object-cover brightness-[0.92]" />}
                <div className="absolute inset-0 bg-gradient-to-t from-black/60 via-black/20 to-transparent" />
                <nav aria-label="Train Header Actions" className="absolute inset-x-0 top-3 z-10 flex items-center justify-between px-margin">
                    <HeroButton icon="arrow_back" label="Back" onClick={onBack} />
                    <div className="flex items-center gap-2">
                        <HeroButton icon="share" label="Share Train Status" share={shareData} />
                        <HeroButton icon="star" label={favourite ? 'Remove from Favorites' : 'Add to Favorites'} onClick={toggleFavourite} active={favourite} />
                    </div>
                </nav>
                <div className="absolute bottom-3 left-margin flex items-center gap-1.5 rounded-md border border-white/20 bg-black/50 px-2.5 py-1 text-on-primary backdrop-blur-md">
                    <Icon name="train" className="text-[14px]" />
                    <span className="font-label-sm text-label-sm font-semibold tracking-wider uppercase">{train.type}</span>
                </div>
            </section>
        </>
    );
}

export function TrainIdentity({ train, share = true }: { train: TrainDetail; share?: boolean }) {
    return (
        <div className="flex items-start justify-between gap-3">
            <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2">
                    <h1 className="font-headline-lg text-headline-lg font-bold tracking-tight text-on-surface">
                        <span className="tabular-nums">{train.number}</span> – {train.name}
                    </h1>
                    <RunningPill status={train.live.status} className="font-label-sm text-label-sm" />
                </div>
                <p className="mt-1 flex flex-wrap items-center gap-1 font-body-md text-body-md text-on-surface-variant">
                    <span>
                        {train.from.name} ({train.from.code})
                    </span>
                    <Icon name="arrow_forward" className="text-[16px] text-outline" />
                    <span>
                        {train.to.name} ({train.to.code})
                    </span>
                </p>
            </div>
            {share && <ShareSquareButton train={train} />}
        </div>
    );
}

function ShareSquareButton({ train }: { train: TrainDetail }) {
    const share = async () => {
        const data = { title: `${train.number} – ${train.name}`, url: window.location.href };
        try {
            if (navigator.share) await navigator.share(data);
            else await navigator.clipboard.writeText(data.url);
        } catch {
            // dismissed
        }
    };
    return (
        <button
            type="button"
            aria-label="Share Route"
            onClick={share}
            className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-surface-container-low text-primary transition-colors hover:bg-surface-container-high active:scale-95"
        >
            <Icon name="share" className="text-[20px]" />
        </button>
    );
}
