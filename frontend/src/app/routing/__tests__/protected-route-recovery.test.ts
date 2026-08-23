import {
    describe,
    expect,
    it,
    vi,
} from 'vitest';

import {
    recoverProtectedRouteUnavailableSource,
} from '@/app/routing/protected-route-recovery';

function createRecoveryDependencies(
    authenticationStatus:
        'authenticated'
        | 'membership-context-required'
        = 'authenticated',
) {
    return {
        authenticationStatus,

        authentication: {
            bootstrap:
                vi.fn()
                    .mockResolvedValue(
                        undefined,
                    ),
        },

        membership: {
            bootstrap:
                vi.fn()
                    .mockResolvedValue(
                        undefined,
                    ),
        },

        workspace: {
            bootstrap:
                vi.fn()
                    .mockResolvedValue(
                        undefined,
                    ),
        },

        capabilities: {
            refresh:
                vi.fn()
                    .mockResolvedValue(
                        undefined,
                    ),
        },

        reportFailure:
            vi.fn(),
    };
}

describe(
    'protected route unavailable recovery',
    () => {
        it('delegates authentication recovery only to BrowserAuth bootstrap', async () => {
            const dependencies =
                createRecoveryDependencies();

            await recoverProtectedRouteUnavailableSource(
                'authentication',
                dependencies,
            );

            expect(
                dependencies
                    .authentication
                    .bootstrap,
            ).toHaveBeenCalledTimes(1);

            expect(
                dependencies
                    .membership
                    .bootstrap,
            ).not.toHaveBeenCalled();

            expect(
                dependencies
                    .workspace
                    .bootstrap,
            ).not.toHaveBeenCalled();

            expect(
                dependencies
                    .capabilities
                    .refresh,
            ).not.toHaveBeenCalled();
        });

        it('retries Membership discovery without restoration after authoritative authenticated context', async () => {
            const dependencies =
                createRecoveryDependencies(
                    'authenticated',
                );

            await recoverProtectedRouteUnavailableSource(
                'membership',
                dependencies,
            );

            expect(
                dependencies
                    .membership
                    .bootstrap,
            ).toHaveBeenCalledTimes(1);

            expect(
                dependencies
                    .membership
                    .bootstrap,
            ).toHaveBeenCalledWith({
                restoreHint:
                    false,
            });

            expect(
                dependencies
                    .authentication
                    .bootstrap,
            ).not.toHaveBeenCalled();

            expect(
                dependencies
                    .workspace
                    .bootstrap,
            ).not.toHaveBeenCalled();

            expect(
                dependencies
                    .capabilities
                    .refresh,
            ).not.toHaveBeenCalled();
        });

        it('preserves initial BrowserSession Membership restoration eligibility during recovery', async () => {
            const dependencies =
                createRecoveryDependencies(
                    'membership-context-required',
                );

            await recoverProtectedRouteUnavailableSource(
                'membership',
                dependencies,
            );

            expect(
                dependencies
                    .membership
                    .bootstrap,
            ).toHaveBeenCalledTimes(1);

            expect(
                dependencies
                    .membership
                    .bootstrap,
            ).toHaveBeenCalledWith({
                restoreHint:
                    true,
            });
        });

        it('delegates Workspace recovery only to Workspace bootstrap', async () => {
            const dependencies =
                createRecoveryDependencies();

            await recoverProtectedRouteUnavailableSource(
                'workspace',
                dependencies,
            );

            expect(
                dependencies
                    .workspace
                    .bootstrap,
            ).toHaveBeenCalledTimes(1);

            expect(
                dependencies
                    .authentication
                    .bootstrap,
            ).not.toHaveBeenCalled();

            expect(
                dependencies
                    .membership
                    .bootstrap,
            ).not.toHaveBeenCalled();

            expect(
                dependencies
                    .capabilities
                    .refresh,
            ).not.toHaveBeenCalled();
        });

        it('delegates authorization recovery only to Capability refresh', async () => {
            const dependencies =
                createRecoveryDependencies();

            await recoverProtectedRouteUnavailableSource(
                'authorization',
                dependencies,
            );

            expect(
                dependencies
                    .capabilities
                    .refresh,
            ).toHaveBeenCalledTimes(1);

            expect(
                dependencies
                    .authentication
                    .bootstrap,
            ).not.toHaveBeenCalled();

            expect(
                dependencies
                    .membership
                    .bootstrap,
            ).not.toHaveBeenCalled();

            expect(
                dependencies
                    .workspace
                    .bootstrap,
            ).not.toHaveBeenCalled();
        });

        it('reports asynchronous recovery failures instead of leaking an unhandled rejection', async () => {
            const dependencies =
                createRecoveryDependencies();

            const recoveryFailure =
                new Error(
                    'Recovery invariant failed.',
                );

            dependencies
                .capabilities
                .refresh
                .mockRejectedValueOnce(
                    recoveryFailure,
                );

            await expect(
                recoverProtectedRouteUnavailableSource(
                    'authorization',
                    dependencies,
                ),
            ).resolves.toBeUndefined();

            expect(
                dependencies.reportFailure,
            ).toHaveBeenCalledTimes(1);

            expect(
                dependencies.reportFailure,
            ).toHaveBeenCalledWith(
                recoveryFailure,
            );
        });
    },
);
