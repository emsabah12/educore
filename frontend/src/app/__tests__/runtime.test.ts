import {
    describe,
    expect,
    it,
} from 'vitest';

import {
    createApplicationRuntime,
} from '@/app/runtime';

describe(
    'createApplicationRuntime',
    () => {
        it('creates isolated application runtime instances', () => {
            const firstRuntime =
                createApplicationRuntime();

            const secondRuntime =
                createApplicationRuntime();

            expect(
                firstRuntime.apiClient,
            ).not.toBe(
                secondRuntime.apiClient,
            );

            expect(
                firstRuntime.auth,
            ).not.toBe(
                secondRuntime.auth,
            );

            expect(
                firstRuntime.membership,
            ).not.toBe(
                secondRuntime.membership,
            );

            expect(
                firstRuntime.queryClient,
            ).not.toBe(
                secondRuntime.queryClient,
            );

            expect(
                firstRuntime.router,
            ).not.toBe(
                secondRuntime.router,
            );
        });
    },
);
