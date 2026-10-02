import { createStore } from './createStore';

const favourites = createStore<string[]>('railway.favouriteTrains', []);
const alerts = createStore<string[]>('railway.trainAlerts', []);

function toggleIn(store: typeof favourites, id: string) {
    store.set((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));
}

/** Locally remembered favourites / alert preferences for trains (no account needed). */
export function useTrainFlags(number: string) {
    const favs = favourites.useStore();
    const alertList = alerts.useStore();
    return {
        favourite: favs.includes(number),
        alert: alertList.includes(number),
        toggleFavourite: () => toggleIn(favourites, number),
        toggleAlert: () => toggleIn(alerts, number),
    };
}
