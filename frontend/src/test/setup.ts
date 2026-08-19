import '@testing-library/jest-dom/vitest';

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
    apiMockServer.resetHandlers();
});

afterAll(() => {
    apiMockServer.close();
});
