import '@testing-library/jest-dom/vitest';

import { cleanup } from '@testing-library/react';
import {
    afterAll,
    afterEach,
    beforeAll,
} from 'vitest';

import { apiMockServer } from '@/test/server';

beforeAll(() => {
    apiMockServer.listen({
        onUnhandledRequest: 'error',
    });
});

afterEach(() => {
    /*
     * Keep every React test isolated from DOM and Effect
     * state created by the previous test.
     *
     * Cleanup runs before MSW handlers are reset so React
     * Effect teardown, including AbortController cleanup,
     * completes while the test's request environment still
     * exists.
     */
    cleanup();

    apiMockServer.resetHandlers();
});

afterAll(() => {
    apiMockServer.close();
});
