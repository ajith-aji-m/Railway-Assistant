import { createStore } from './createStore';

/** The train most recently opened — the "Live Map" tab returns to it. */
const store = createStore<string | null>('railway.lastTrain', null);

export const useLastTrain = store.useStore;
export const rememberTrain = (number: string) => store.set(number);
