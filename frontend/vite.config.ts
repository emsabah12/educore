import {
    fileURLToPath,
    URL,
} from 'node:url';

import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import {
    defineConfig,
} from 'vite';

import {
    configureContextRaceResponseGate,
    contextRaceResponseGateEnabledValue,
    contextRaceResponseGateEnvironmentVariable,
} from './e2e/support/context-race-response-gate.ts';

const frontendRoot =
    fileURLToPath(
        new URL(
            '.',
            import.meta.url,
        ),
    );

/*
 * The browser must observe canonical API traffic as
 * same-origin traffic.
 *
 * Local Vite therefore acts as the development reverse
 * proxy only; Laravel remains the Browser BFF owner.
 *
 * CI or another development environment may override the
 * target without changing application source.
 */
const browserBffOrigin =
    process.env
        .EDUCORE_BFF_ORIGIN
    ?? 'http://127.0.0.1:8000';

const contextRaceResponseGateEnabled =
    process.env[
        contextRaceResponseGateEnvironmentVariable
    ]
    === contextRaceResponseGateEnabledValue;

export default defineConfig({
    root:
        frontendRoot,

    plugins: [
        react(),
        tailwindcss(),
    ],

    resolve: {
        alias: {
            '@':
                fileURLToPath(
                    new URL(
                        './src',
                        import.meta.url,
                    ),
                ),
        },
    },

    cacheDir:
        fileURLToPath(
            new URL(
                '../node_modules/.vite/educore-frontend',
                import.meta.url,
            ),
        ),

    server: {
        /*
         * Browser-visible requests remain:
         *
         *     http://127.0.0.1:5173/api/...
         *
         * The proxy forwards those requests to Laravel
         * without moving cross-origin responsibility into
         * React application code.
         */
        proxy: {
            '/api': {
                target:
                    browserBffOrigin,

                changeOrigin:
                    true,

                ...(
                    contextRaceResponseGateEnabled
                        ? {
                            configure:
                                configureContextRaceResponseGate,
                        }
                        : {}
                ),
            },
        },
    },

    build: {
        outDir:
            'dist',

        emptyOutDir:
            true,

        rollupOptions: {
            output: {
                /*
                 * Vendor chunk terpisah untuk framework inti yang
                 * jarang berubah antar-deploy (react, react-dom,
                 * react-router, react-query) — browser bisa
                 * meng-cache chunk ini lintas rilis selama
                 * dependency-nya tidak berubah, terlepas dari
                 * app code yang berubah tiap deploy.
                 *
                 * CATATAN JUJUR: ini TIDAK mengurangi total byte
                 * JavaScript yang dikirim (javascriptAggregateBytes
                 * di bundle-regression-check tetap sama) — cuma
                 * mengubah BATAS antar-file, bukan jumlah byte-nya.
                 * Manfaatnya murni caching, bukan pengurangan
                 * ukuran.
                 */
                manualChunks:
                    (id: string) =>
                        /node_modules\/(react|react-dom|react-router|@tanstack\/react-query)\//.test(
                            id,
                        )
                            ? 'vendor'
                            : undefined,
            },
        },
    },
});
