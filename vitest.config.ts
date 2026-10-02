import { resolve } from 'node:path';
import { defineConfig } from 'vitest/config';

// Unit tests for pure frontend helpers (no browser/DOM needed).
export default defineConfig({
    resolve: {
        alias: { '@': resolve(__dirname, 'resources/js') },
    },
    test: {
        include: ['resources/js/**/*.test.ts'],
        environment: 'node',
    },
});
