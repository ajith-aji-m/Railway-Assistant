import type { FacilityKey } from '@/types/railway';

export const FACILITIES: Record<FacilityKey, { icon: string; label: string }> = {
    wifi: { icon: 'wifi', label: 'Free Wi-Fi' },
    food: { icon: 'restaurant', label: 'Food Court' },
    taxi: { icon: 'local_taxi', label: 'Prepaid Taxi' },
    elevator: { icon: 'accessible', label: 'Elevator' },
    charging: { icon: 'battery_charging_full', label: 'Charging Point' },
};
