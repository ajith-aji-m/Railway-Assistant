// Map tiles — standard OpenStreetMap by default. Override with VITE_MAP_TILE_URL /
// VITE_MAP_ATTRIBUTION to swap providers without touching components.
// Please respect the OSM tile usage policy: https://operations.osmfoundation.org/policies/tiles/

export const mapConfig = {
    tileUrl: import.meta.env.VITE_MAP_TILE_URL || 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
    attribution:
        import.meta.env.VITE_MAP_ATTRIBUTION || '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noreferrer">OpenStreetMap</a> contributors',
    maxZoom: 18,
    trainZoom: 11,
};
