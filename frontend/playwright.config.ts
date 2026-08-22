import {
    fileURLToPath,
    URL,
} from 'node:url';

import {
    defineConfig,
    devices,
} from '@playwright/test';

const repositoryRoot =
    fileURLToPath(
        new URL(
            '../',
            import.meta.url,
        ),
    );

export default defineConfig({
    testDir:
        './e2e',

    /*
     * Playwright owns only explicit *.e2e.ts files.
     *
     * This prevents Vitest from claiming Playwright suites
     * through its normal *.test.* / *.spec.* discovery.
     */
    testMatch:
        '**/*.e2e.ts',

    /*
     * Authenticated E2E currently shares one deterministic
     * database fixture.
     *
     * Keep the suite serial until per-worker database
     * isolation is deliberately introduced.
     */
    fullyParallel:
        false,

    workers:
        1,

    forbidOnly:
        true,

    retries:
        0,

    reporter:
        'list',

    timeout:
        30_000,

    expect: {
        timeout:
            5_000,
    },

    use: {
        baseURL:
            'http://127.0.0.1:5173',

        trace:
            'retain-on-failure',

        screenshot:
            'only-on-failure',
    },

    outputDir:
        '../node_modules/.cache/educore-playwright',

    webServer: [
        {
            cwd:
                repositoryRoot,

            /*
             * Fixture preparation is explicit and guarded
             * by E2EBrowserAuthenticationSeeder itself.
             *
             * The seeder refuses every environment/database
             * except e2e + educore_e2e.
             */
            command:
                'php artisan db:seed --env=e2e --class="Database\\Seeders\\E2EBrowserAuthenticationSeeder" && php artisan --env=e2e serve --host=127.0.0.1 --port=8000',

            url:
                'http://127.0.0.1:8000/up',

            timeout:
                120_000,

            reuseExistingServer:
                false,
        },

        {
            cwd:
                repositoryRoot,

            command:
                'npm run frontend:dev -- --host 127.0.0.1 --port 5173 --strictPort',

            url:
                'http://127.0.0.1:5173/login',

            timeout:
                120_000,

            reuseExistingServer:
                false,
        },
    ],

    projects: [
        {
            name:
                'chromium',

            use: {
                ...devices[
                    'Desktop Chrome'
                ],
            },
        },
    ],
});