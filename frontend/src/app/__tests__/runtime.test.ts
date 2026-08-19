import { describe, expect, it } from 'vitest';

import { createApplicationRuntime } from '@/app/runtime';

describe('createApplicationRuntime', () => {
    it('creates isolated QueryClient and router instances', () => {
        const firstRuntime = createApplicationRuntime();
        const secondRuntime = createApplicationRuntime();

        expect(firstRuntime.queryClient).not.toBe(
            secondRuntime.queryClient,
        );

        expect(firstRuntime.router).not.toBe(
            secondRuntime.router,
        );
    });
});
