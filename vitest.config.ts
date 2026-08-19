import {
    fileURLToPath,
    URL,
} from 'node:url';

import react from '@vitejs/plugin-react';
import { defineConfig } from 'vitest/config';

const frontendRoot = fileURLToPath(
    new URL('./frontend/', import.meta.url),
);

export default defineConfig({
    root: frontendRoot,

    cacheDir: fileURLToPath(
        new URL(
            './node_modules/.vite/educore-frontend-test',
            import.meta.url,
        ),
    ),

    plugins: [
        react(),
    ],

    resolve: {
        alias: {
            '@': fileURLToPath(
                new URL('./frontend/src', import.meta.url),
            ),
        },
    },

    test: {
        environment: 'jsdom',
        setupFiles: [
            './src/test/setup.ts',
        ],
        clearMocks: true,
        restoreMocks: true,
    },
});
