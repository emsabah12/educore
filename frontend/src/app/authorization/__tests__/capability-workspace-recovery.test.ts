import {
    describe,
    expect,
    it,
    vi,
} from 'vitest';

import type {
    CapabilityState,
} from '@/platform/authorization';
import {
    createCapabilityWorkspaceRecoveryCoordinator,
    type CapabilityRecoverySource,
    type WorkspaceRecoveryTarget,
} from '@/app/authorization/capability-workspace-recovery';
import type {
    BrowserApiFailure,
} from '@/platform/api';
import type {
    CanonicalMembershipContext,
} from '@/platform/membership';
import type {
    WorkspaceContextState,
    WorkspaceSummary,
} from '@/platform/workspace';

const membershipId =
    '018f3b6a-7c20-7abc-8def-1234567890ab';

const tenantId =
    '018f3b6a-7c20-7bcd-8def-1234567890ab';

const organizationalAssignmentId =
    '018f3b6a-7c20-7cde-8def-1234567890ab';

const organizationId =
    '018f3b6a-7c20-7def-8def-1234567890ab';

const context:
    CanonicalMembershipContext = {
        membership: {
            id:
                membershipId,

            status:
                'ACTIVE',
        },

        tenant: {
            id:
                tenantId,

            name:
                'EduCore School',

            subdomain:
                'school',
        },
    };

const tenantWorkspace:
    WorkspaceSummary = {
        type:
            'TENANT',

        organizational_assignment_id:
            null,

        organization_id:
            null,

        organization_unit_id:
            null,

        label:
            'EduCore School',
    };

const organizationWorkspace:
    WorkspaceSummary = {
        type:
            'ORGANIZATION',

        organizational_assignment_id:
            organizationalAssignmentId,

        organization_id:
            organizationId,

        organization_unit_id:
            null,

        label:
            'EduCore Organization',
    };

const contextDeniedFailure:
    BrowserApiFailure = {
        ok:
            false,

        kind:
            'response',

        status:
            403,

        error: {
            status:
                'error',

            code:
                'ORGANIZATIONAL_CONTEXT_DENIED',

            message:
                'Organizational context is no longer available.',
        },
    };

const networkFailure:
    BrowserApiFailure = {
        ok:
            false,

        kind:
            'network',

        cause:
            new Error(
                'offline',
            ),
    };

function readyWorkspaceState(
    current:
        WorkspaceSummary,
): WorkspaceContextState {
    return {
        status:
            'ready',

        context,

        tenant: {
            id:
                tenantId,

            name:
                'EduCore School',
        },

        workspaces: [
            tenantWorkspace,
            organizationWorkspace,
        ],

        current,

        failure:
            null,
    };
}

interface ControlledCapabilitySource {
    readonly source:
        CapabilityRecoverySource;

    setState(
        state:
            CapabilityState,
    ): void;
}

function createCapabilitySource(
    initialState:
        CapabilityState = {
            status:
                'unresolved',
        },
): ControlledCapabilitySource {
    let state =
        initialState;

    const listeners =
        new Set<
            () => void
        >();

    return {
        source: {
            getState() {
                return state;
            },

            subscribe(
                listener,
            ) {
                listeners.add(
                    listener,
                );

                return () => {
                    listeners.delete(
                        listener,
                    );
                };
            },
        },

        setState(
            nextState,
        ) {
            state =
                nextState;

            for (
                const listener
                of listeners
            ) {
                listener();
            }
        },
    };
}

interface Deferred<Value> {
    readonly promise:
        Promise<Value>;

    resolve(
        value:
            Value,
    ): void;

    reject(
        reason:
            unknown,
    ): void;
}

function createDeferred<Value>():
    Deferred<Value> {
    let resolve:
        (
            value:
                Value,
        ) => void =
            () => {
                throw new Error(
                    'Deferred resolver is unavailable.',
                );
            };

    let reject:
        (
            reason:
                unknown,
        ) => void =
            () => {
                throw new Error(
                    'Deferred rejector is unavailable.',
                );
            };

    const promise =
        new Promise<Value>(
            (
                promiseResolve,
                promiseReject,
            ) => {
                resolve =
                    promiseResolve;

                reject =
                    promiseReject;
            },
        );

    return {
        promise,
        resolve,
        reject,
    };
}

function createWorkspaceRecoveryTarget(
    current:
        WorkspaceSummary,
    recover:
        (
            failure:
                BrowserApiFailure,
        ) => Promise<
            WorkspaceContextState
        >,
) {
    let state =
        readyWorkspaceState(
            current,
        );

    const recoveryCalls:
        BrowserApiFailure[] = [];

    const target:
        WorkspaceRecoveryTarget = {
        getState() {
            return state;
        },

        async recoverStaleWorkspace(
            failure,
        ) {
            recoveryCalls.push(
                failure,
            );

            const recovered =
                await recover(
                    failure,
                );

            state =
                recovered;

            return recovered;
        },
    };

    return {
        target,
        recoveryCalls,
    };
}

describe(
    'Capability Workspace recovery coordinator',
    () => {
        it('ignores capability failures that are not ORGANIZATIONAL_CONTEXT_DENIED', () => {
            const capabilities =
                createCapabilitySource();

            const workspace =
                createWorkspaceRecoveryTarget(
                    organizationWorkspace,
                    async () =>
                        readyWorkspaceState(
                            tenantWorkspace,
                        ),
                );

            const reportFailure =
                vi.fn();

            const coordinator =
                createCapabilityWorkspaceRecoveryCoordinator(
                    capabilities.source,
                    workspace.target,
                    {
                        reportFailure,
                    },
                );

            capabilities.setState({
                status:
                    'unavailable',

                failure:
                    networkFailure,
            });

            expect(
                workspace.recoveryCalls,
            ).toHaveLength(
                0,
            );

            expect(
                reportFailure,
            ).not.toHaveBeenCalled();

            coordinator.dispose();
        });

        it('ignores ORGANIZATIONAL_CONTEXT_DENIED while TENANT is already authoritative', () => {
            const capabilities =
                createCapabilitySource();

            const workspace =
                createWorkspaceRecoveryTarget(
                    tenantWorkspace,
                    async () =>
                        readyWorkspaceState(
                            tenantWorkspace,
                        ),
                );

            const coordinator =
                createCapabilityWorkspaceRecoveryCoordinator(
                    capabilities.source,
                    workspace.target,
                    {
                        reportFailure:
                            vi.fn(),
                    },
                );

            capabilities.setState({
                status:
                    'unavailable',

                failure:
                    contextDeniedFailure,
            });

            expect(
                workspace.recoveryCalls,
            ).toHaveLength(
                0,
            );

            coordinator.dispose();
        });

        it('routes organizational context denial to Workspace stale recovery exactly once while recovery is in flight', async () => {
            const capabilities =
                createCapabilitySource();

            const deferred =
                createDeferred<
                    WorkspaceContextState
                >();

            const workspace =
                createWorkspaceRecoveryTarget(
                    organizationWorkspace,
                    () =>
                        deferred.promise,
                );

            const coordinator =
                createCapabilityWorkspaceRecoveryCoordinator(
                    capabilities.source,
                    workspace.target,
                    {
                        reportFailure:
                            vi.fn(),
                    },
                );

            capabilities.setState({
                status:
                    'unavailable',

                failure:
                    contextDeniedFailure,
            });

            /*
             * A duplicate notification of the same denied
             * authority must not start a second recovery
             * while the first recovery still owns the
             * transition.
             */
            capabilities.setState({
                status:
                    'unavailable',

                failure:
                    contextDeniedFailure,
            });

            expect(
                workspace.recoveryCalls,
            ).toEqual([
                contextDeniedFailure,
            ]);

            deferred.resolve(
                readyWorkspaceState(
                    tenantWorkspace,
                ),
            );

            await deferred.promise;

            coordinator.dispose();
        });

        it('reports asynchronous Workspace recovery failures instead of creating an unhandled rejection', async () => {
            const capabilities =
                createCapabilitySource();

            const recoveryFailure =
                new Error(
                    'Workspace recovery failed.',
                );

            const workspace =
                createWorkspaceRecoveryTarget(
                    organizationWorkspace,
                    async () => {
                        throw recoveryFailure;
                    },
                );

            const reportFailure =
                vi.fn();

            const coordinator =
                createCapabilityWorkspaceRecoveryCoordinator(
                    capabilities.source,
                    workspace.target,
                    {
                        reportFailure,
                    },
                );

            capabilities.setState({
                status:
                    'unavailable',

                failure:
                    contextDeniedFailure,
            });

            await Promise.resolve();
            await Promise.resolve();

            expect(
                reportFailure,
            ).toHaveBeenCalledTimes(
                1,
            );

            expect(
                reportFailure,
            ).toHaveBeenCalledWith(
                recoveryFailure,
            );

            coordinator.dispose();
        });

        it('detaches from Capability lifecycle after disposal', () => {
            const capabilities =
                createCapabilitySource();

            const workspace =
                createWorkspaceRecoveryTarget(
                    organizationWorkspace,
                    async () =>
                        readyWorkspaceState(
                            tenantWorkspace,
                        ),
                );

            const coordinator =
                createCapabilityWorkspaceRecoveryCoordinator(
                    capabilities.source,
                    workspace.target,
                    {
                        reportFailure:
                            vi.fn(),
                    },
                );

            coordinator.dispose();

            capabilities.setState({
                status:
                    'unavailable',

                failure:
                    contextDeniedFailure,
            });

            expect(
                workspace.recoveryCalls,
            ).toHaveLength(
                0,
            );
        });
    },
);
