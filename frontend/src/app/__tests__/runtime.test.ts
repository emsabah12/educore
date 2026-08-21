import {
    describe,
    expect,
    it,
    vi,
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
                firstRuntime.workspace,
            ).not.toBe(
                secondRuntime.workspace,
            );

            expect(
                firstRuntime.capabilities,
            ).not.toBe(
                secondRuntime.capabilities,
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

            firstRuntime
                .capabilities
                .dispose();

            firstRuntime
                .workspace
                .dispose();

            secondRuntime
                .capabilities
                .dispose();

            secondRuntime
                .workspace
                .dispose();
        });

        it('owns idempotent disposal of composed long-lived runtimes', () => {
            const runtime =
                createApplicationRuntime();

            const capabilitiesDispose =
                vi.spyOn(
                    runtime.capabilities,
                    'dispose',
                );

            const workspaceDispose =
                vi.spyOn(
                    runtime.workspace,
                    'dispose',
                );

            runtime.dispose();

            expect(
                capabilitiesDispose,
            ).toHaveBeenCalledTimes(
                1,
            );

            expect(
                workspaceDispose,
            ).toHaveBeenCalledTimes(
                1,
            );

            /*
            * Application ownership is idempotent even though
            * individual child runtimes also protect themselves.
            *
            * This prevents accidental repeated teardown from
            * HMR or future mounting infrastructure.
            */
            runtime.dispose();

            expect(
                capabilitiesDispose,
            ).toHaveBeenCalledTimes(
                1,
            );

            expect(
                workspaceDispose,
            ).toHaveBeenCalledTimes(
                1,
            );
        });
    },
);
