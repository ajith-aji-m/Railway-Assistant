// Central place for app URLs (mirrors routes/web.php).

export const urls = {
    stations: (params?: Record<string, string | number>) => withQuery('/', params),
    station: (code: string, tab?: 'arrivals' | 'departures') => withQuery(`/stations/${code}`, tab ? { tab } : undefined),
    trainSearch: (params?: { q?: string; all?: 1 }) => withQuery('/trains', params as Record<string, string | number> | undefined),
    train: (number: string) => `/trains/${number}`,
    trainMap: (number: string) => `/trains/${number}/map`,
    map: () => '/map',
    settings: () => '/settings',
};

function withQuery(path: string, params?: Record<string, string | number>): string {
    if (!params || Object.keys(params).length === 0) return path;
    return `${path}?${new URLSearchParams(Object.entries(params).map(([k, v]) => [k, String(v)])).toString()}`;
}
