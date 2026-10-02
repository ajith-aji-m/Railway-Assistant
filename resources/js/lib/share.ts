/** Share the current page via the Web Share API, falling back to copying the link. */
export async function sharePage(title: string, text?: string): Promise<'shared' | 'copied' | 'dismissed'> {
    const url = window.location.href;
    try {
        if (navigator.share) {
            await navigator.share({ title, text, url });
            return 'shared';
        }
        await navigator.clipboard.writeText(url);
        return 'copied';
    } catch {
        return 'dismissed';
    }
}
