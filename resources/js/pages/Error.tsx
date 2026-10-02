import { AppShell } from '@/components/layout/AppShell';
import { ButtonLink } from '@/components/ui/Button';
import { StateCard } from '@/components/ui/StateCard';
import { urls } from '@/lib/urls';

export default function ErrorPage({ status }: { status: number }) {
    const notFound = status === 404;

    return (
        <AppShell title={notFound ? 'Not Found' : 'Error'} nav={false}>
            <main className="flex flex-1 flex-col px-margin pt-space-lg">
                <StateCard
                    tone={notFound ? 'neutral' : 'error'}
                    icon={notFound ? 'search_off' : 'warning'}
                    badge={`Error ${status}`}
                    title={notFound ? 'Not Found' : 'Something Went Wrong'}
                    description={
                        notFound
                            ? 'We couldn’t find that station or train. Check the code or number and try again.'
                            : 'The railway service is temporarily unavailable. Please try again shortly.'
                    }
                >
                    <ButtonLink href={urls.trainSearch()} icon="search">
                        Search Trains
                    </ButtonLink>
                    <ButtonLink href={urls.stations()} variant="neutral" icon="near_me">
                        Back to Stations
                    </ButtonLink>
                </StateCard>
            </main>
        </AppShell>
    );
}
