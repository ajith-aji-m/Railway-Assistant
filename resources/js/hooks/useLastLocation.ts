import type { LatLng } from '@/types/railway';
import { createStore } from './createStore';

/** Last detected position, so returning to the Stations tab keeps the nearby list. */
const store = createStore<LatLng | null>('railway.lastLocation', null);

export const getLastLocation = store.get;
export const setLastLocation = store.set;
