import { toSavedLocation, validSavedLocation, type SavedLocation } from '@/lib/location';
import type { LatLng } from '@/types/railway';
import { createStore } from './createStore';

/** Last detected position + timestamp, so returning to the Stations tab keeps the nearby list. */
const store = createStore<SavedLocation | null>('railway.lastLocation', null);

/** The saved position while it is still valid (well-formed and recent), otherwise null. */
export const getLastLocation = (): LatLng | null => validSavedLocation(store.get());
export const setLastLocation = (position: LatLng | null) => store.set(position ? toSavedLocation(position) : null);
