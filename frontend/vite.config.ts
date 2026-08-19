import { fileURLToPath, URL } from 'node:url';

import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';

const frontendRoot = fileURLToPath(
    new URL('.', import.meta.url),
);

export default defineConfig({
    root: frontendRoot,

    plugins: [
        react(),
        tailwindcss(),
    ],

    resolve: {
        alias: {
            '@': fileURLToPath(
                new URL('./src', import.meta.url),
            ),
        },
    },

    cacheDir: fileURLToPath(
        new URL(
            '../node_modules/.vite/educore-frontend',
            import.meta.url,
        ),
    ),

    build: {
        outDir: 'dist',
        emptyOutDir: true,
    },
});
