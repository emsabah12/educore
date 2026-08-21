import {
    describe,
    expect,
    it,
} from 'vitest';

import {
    createCapabilityRuntime,
    type CapabilityMembershipRuntime,
    type CapabilityMembershipSourceState,
    type CapabilityProjectionOperations,
    type CapabilityWorkspaceRuntime,
    type CapabilityWorkspaceSourceState,
    type TenantCapabilitySuccess,
    type WorkspaceCapabilitySuccess,
} from '@/platform/authorization';
import type {
    CanonicalMembershipContext,
} from '@/platform/membership';
import type {
    WorkspaceSummary,
} from '@/platform/workspace';

const membershipId =
    '018f3b6a-7c20-7abc-8def-1234567890ab';

const otherMembershipId =
    '018f3b6a-7c20-7bcd-8def-1234567890ab';

const tenantId =
    '018f3b6a-7c20-7cde-8def-1234567890ab';

const organizationalAssignmentId =
    '018f3b6a-7c20-7def-9abc-1234567890ab';

const organizationId =
    '018f3b6a-7c20-7abc-9def-1234567890ab';

const organizationUnitId =
    '018f3b6a-7c20-7def-9def-1234567890ab';

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
                'educore-school',
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
            'EduCore High School',
    };

const organizationUnitWorkspace:
    WorkspaceSummary = {
        type:
            'ORGANIZATION_UNIT',

        organizational_assignment_id:
            organizationalAssignmentId,

        organization_id:
            organizationId,

        organization_unit_id:
            organizationUnitId,

        label:
            'Academic Office',
    };

const tenantCapabilitySuccess:
    TenantCapabilitySuccess = {
        status:
            'success',

        data: {
            scope: {
                type:
                    'tenant',

                tenant_id:
                    tenantId,

                membership_id:
                    membershipId,
            },

            is_global_superadmin:
                false,

            permissions: [
                'academic.grades.write',
            ],
        },
    };

const organizationCapabilitySuccess:
    WorkspaceCapabilitySuccess = {
        status:
            'success',

        data: {
            scope: {
                type:
                    'organization',

                tenant_id:
                    tenantId,

                membership_id:
                    membershipId,

                organizational_assignment_id:
                    organizationalAssignmentId,

                organization_id:
                    organizationId,

                organization_unit_id:
                    null,
            },

            is_global_superadmin:
                false,

            permissions: [
                'dormitory.rooms.manage',
            ],
        },
    };

const organizationUnitCapabilitySuccess:
    WorkspaceCapabilitySuccess = {
        status:
            'success',

        data: {
            scope: {
                type:
                    'organization_unit',

                tenant_id:
                    tenantId,

                membership_id:
                    membershipId,

                organizational_assignment_id:
                    organizationalAssignmentId,

                organization_id:
                    organizationId,

                organization_unit_id:
                    organizationUnitId,
            },

            is_global_superadmin:
                false,

            permissions: [
                'academic.grades.write',
            ],
        },
    };

type TenantProjectionResult =
    Awaited<
        ReturnType<
            CapabilityProjectionOperations[
                'projectTenant'
            ]
        >
    >;

type WorkspaceProjectionResult =
    Awaited<
        ReturnType<
            CapabilityProjectionOperations[
                'projectWorkspace'
            ]
        >
    >;

interface MutableMembershipSource {
    readonly runtime:
        CapabilityMembershipRuntime;

    setState(
        state:
            CapabilityMembershipSourceState,
    ): void;
}

interface MutableWorkspaceSource {
    readonly runtime:
        CapabilityWorkspaceRuntime;

    setState(
        state:
            CapabilityWorkspaceSourceState,
    ): void;
}

function createMembershipSource(
    initial:
        CapabilityMembershipSourceState,
): MutableMembershipSource {
    let state =
        initial;

    const listeners =
        new Set<
            () => void
        >();

    return {
        runtime: {
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

function createWorkspaceSource(
    initial:
        CapabilityWorkspaceSourceState,
): MutableWorkspaceSource {
    let state =
        initial;

    const listeners =
        new Set<
            () => void
        >();

    return {
        runtime: {
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

function readyMembershipState():
    CapabilityMembershipSourceState {
    return {
        status:
            'ready',

        context,
    };
}

function readyWorkspaceState(
    current:
        WorkspaceSummary,
): CapabilityWorkspaceSourceState {
    return {
        status:
            'ready',

        context,

        current,
    };
}

interface OperationsProbe {
    readonly tenantMembershipIds:
        string[];

    readonly workspaceRequests:
        Array<{
            readonly membershipId:
                string;

            readonly organizationalAssignmentId:
                string;
        }>;
}

function createProbe():
    OperationsProbe {
    return {
        tenantMembershipIds:
            [],

        workspaceRequests:
            [],
    };
}

function createOperations(
    probe:
        OperationsProbe,
    tenantResult:
        TenantProjectionResult = {
            ok:
                true,

            status:
                200,

            data:
                tenantCapabilitySuccess,
        },
    workspaceResult:
        WorkspaceProjectionResult = {
            ok:
                true,

            status:
                200,

            data:
                organizationCapabilitySuccess,
        },
): CapabilityProjectionOperations {
    return {
        async projectTenant(
            requestedMembershipId,
        ) {
            probe
                .tenantMembershipIds
                .push(
                    requestedMembershipId,
                );

            return tenantResult;
        },

        async projectWorkspace(
            requestedMembershipId,
            requestedOrganizationalAssignmentId,
        ) {
            probe
                .workspaceRequests
                .push({
                    membershipId:
                        requestedMembershipId,

                    organizationalAssignmentId:
                        requestedOrganizationalAssignmentId,
                });

            return workspaceResult;
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
}

function createDeferred<Value>():
    Deferred<Value> {
    let resolvePromise:
        (
            value:
                Value,
        ) => void
        | undefined;

    const promise =
        new Promise<Value>(
            (
                resolve,
            ) => {
                resolvePromise =
                    resolve;
            },
        );

    return {
        promise,

        resolve(
            value,
        ) {
            if (
                resolvePromise
                    === undefined
            ) {
                throw new Error(
                    'Deferred resolver is unavailable.',
                );
            }

            resolvePromise(
                value,
            );
        },
    };
}

describe(
    'Capability runtime',
    () => {
        it('remains unresolved and dispatches no projection while upstream context is not ready', async () => {
            const probe =
                createProbe();

            const membership =
                createMembershipSource({
                    status:
                        'discovering',
                });

            const workspace =
                createWorkspaceSource({
                    status:
                        'unresolved',
                });

            const runtime =
                createCapabilityRuntime(
                    createOperations(
                        probe,
                    ),
                    membership.runtime,
                    workspace.runtime,
                );

            await expect(
                runtime.bootstrap(),
            ).resolves.toEqual({
                status:
                    'unresolved',
            });

            expect(
                probe.tenantMembershipIds,
            ).toEqual([]);

            expect(
                probe.workspaceRequests,
            ).toEqual([]);

            runtime.dispose();
        });

        it('loads and publishes validated TENANT capability authority', async () => {
            const membership =
                createMembershipSource(
                    readyMembershipState(),
                );

            const workspace =
                createWorkspaceSource(
                    readyWorkspaceState(
                        tenantWorkspace,
                    ),
                );

            const runtime =
                createCapabilityRuntime(
                    createOperations(
                        createProbe(),
                    ),
                    membership.runtime,
                    workspace.runtime,
                );

            await expect(
                runtime.bootstrap(),
            ).resolves.toEqual({
                status:
                    'ready',

                projection:
                    tenantCapabilitySuccess.data,
            });

            runtime.dispose();
        });

        it('loads organizational capability with exact Membership and assignment locators', async () => {
            const probe =
                createProbe();

            const membership =
                createMembershipSource(
                    readyMembershipState(),
                );

            const workspace =
                createWorkspaceSource(
                    readyWorkspaceState(
                        organizationWorkspace,
                    ),
                );

            const runtime =
                createCapabilityRuntime(
                    createOperations(
                        probe,
                    ),
                    membership.runtime,
                    workspace.runtime,
                );

            await runtime.bootstrap();

            expect(
                probe.workspaceRequests,
            ).toEqual([
                {
                    membershipId,

                    organizationalAssignmentId,
                },
            ]);

            expect(
                runtime.getState(),
            ).toEqual({
                status:
                    'ready',

                projection:
                    organizationCapabilitySuccess.data,
            });

            runtime.dispose();
        });

        it('validates ORGANIZATION_UNIT against its exact committed unit scope', async () => {
            const membership =
                createMembershipSource(
                    readyMembershipState(),
                );

            const workspace =
                createWorkspaceSource(
                    readyWorkspaceState(
                        organizationUnitWorkspace,
                    ),
                );

            const runtime =
                createCapabilityRuntime(
                    createOperations(
                        createProbe(),
                        {
                            ok:
                                true,

                            status:
                                200,

                            data:
                                tenantCapabilitySuccess,
                        },
                        {
                            ok:
                                true,

                            status:
                                200,

                            data:
                                organizationUnitCapabilitySuccess,
                        },
                    ),
                    membership.runtime,
                    workspace.runtime,
                );

            await runtime.bootstrap();

            expect(
                runtime.getState(),
            ).toEqual({
                status:
                    'ready',

                projection:
                    organizationUnitCapabilitySuccess.data,
            });

            runtime.dispose();
        });

        it('fails closed on a semantically mismatched successful projection', async () => {
            const mismatched:
                TenantCapabilitySuccess = {
                    ...tenantCapabilitySuccess,

                    data: {
                        ...tenantCapabilitySuccess.data,

                        scope: {
                            ...tenantCapabilitySuccess
                                .data
                                .scope,

                            membership_id:
                                otherMembershipId,
                        },
                    },
                };

            const membership =
                createMembershipSource(
                    readyMembershipState(),
                );

            const workspace =
                createWorkspaceSource(
                    readyWorkspaceState(
                        tenantWorkspace,
                    ),
                );

            const runtime =
                createCapabilityRuntime(
                    createOperations(
                        createProbe(),
                        {
                            ok:
                                true,

                            status:
                                200,

                            data:
                                mismatched,
                        },
                    ),
                    membership.runtime,
                    workspace.runtime,
                );

            await runtime.bootstrap();

            expect(
                runtime.getState(),
            ).toEqual({
                status:
                    'unavailable',

                failure: {
                    ok:
                        false,

                    kind:
                        'scope-mismatch',
                },
            });

            runtime.dispose();
        });

        it('preserves canonical transport failures without inventing capability authority', async () => {
            const failure:
                TenantProjectionResult = {
                    ok:
                        false,

                    kind:
                        'network',

                    cause:
                        new Error(
                            'offline',
                        ),
                };

            const membership =
                createMembershipSource(
                    readyMembershipState(),
                );

            const workspace =
                createWorkspaceSource(
                    readyWorkspaceState(
                        tenantWorkspace,
                    ),
                );

            const runtime =
                createCapabilityRuntime(
                    createOperations(
                        createProbe(),
                        failure,
                    ),
                    membership.runtime,
                    workspace.runtime,
                );

            await runtime.bootstrap();

            expect(
                runtime.getState(),
            ).toEqual({
                status:
                    'unavailable',

                failure,
            });

            runtime.dispose();
        });

        it('invalidates capability authority immediately when Workspace leaves ready state', async () => {
            const membership =
                createMembershipSource(
                    readyMembershipState(),
                );

            const workspace =
                createWorkspaceSource(
                    readyWorkspaceState(
                        tenantWorkspace,
                    ),
                );

            const runtime =
                createCapabilityRuntime(
                    createOperations(
                        createProbe(),
                    ),
                    membership.runtime,
                    workspace.runtime,
                );

            await runtime.bootstrap();

            expect(
                runtime.getState()
                    .status,
            ).toBe(
                'ready',
            );

            workspace.setState({
                status:
                    'switching',

                context,

                current:
                    tenantWorkspace,
            });

            expect(
                runtime.getState(),
            ).toEqual({
                status:
                    'unresolved',
            });

            runtime.dispose();
        });

        it('does not duplicate projection loads for repeated notifications of the same committed context', async () => {
            const probe =
                createProbe();

            const membership =
                createMembershipSource(
                    readyMembershipState(),
                );

            const workspace =
                createWorkspaceSource(
                    readyWorkspaceState(
                        tenantWorkspace,
                    ),
                );

            const runtime =
                createCapabilityRuntime(
                    createOperations(
                        probe,
                    ),
                    membership.runtime,
                    workspace.runtime,
                );

            await runtime.bootstrap();

            membership.setState(
                readyMembershipState(),
            );

            workspace.setState(
                readyWorkspaceState(
                    tenantWorkspace,
                ),
            );

            await Promise.resolve();

            expect(
                probe.tenantMembershipIds,
            ).toHaveLength(
                1,
            );

            runtime.dispose();
        });

        it('ignores a stale capability completion after the committed Workspace changes', async () => {
            const first =
                createDeferred<
                    TenantProjectionResult
                >();

            const probe =
                createProbe();

            const operations:
                CapabilityProjectionOperations = {
                    projectTenant(
                        requestedMembershipId,
                    ) {
                        probe
                            .tenantMembershipIds
                            .push(
                                requestedMembershipId,
                            );

                        return first.promise;
                    },

                    async projectWorkspace(
                        requestedMembershipId,
                        requestedOrganizationalAssignmentId,
                    ) {
                        probe
                            .workspaceRequests
                            .push({
                                membershipId:
                                    requestedMembershipId,

                                organizationalAssignmentId:
                                    requestedOrganizationalAssignmentId,
                            });

                        return {
                            ok:
                                true,

                            status:
                                200,

                            data:
                                organizationCapabilitySuccess,
                        };
                    },
                };

            const membership =
                createMembershipSource(
                    readyMembershipState(),
                );

            const workspace =
                createWorkspaceSource(
                    readyWorkspaceState(
                        tenantWorkspace,
                    ),
                );

            const runtime =
                createCapabilityRuntime(
                    operations,
                    membership.runtime,
                    workspace.runtime,
                );

            const firstLoad =
                runtime.bootstrap();

            expect(
                runtime.getState()
                    .status,
            ).toBe(
                'loading',
            );

            workspace.setState({
                status:
                    'switching',

                context,

                current:
                    tenantWorkspace,
            });

            workspace.setState(
                readyWorkspaceState(
                    organizationWorkspace,
                ),
            );

            await Promise.resolve();
            await Promise.resolve();

            expect(
                runtime.getState(),
            ).toEqual({
                status:
                    'ready',

                projection:
                    organizationCapabilitySuccess.data,
            });

            first.resolve({
                ok:
                    true,

                status:
                    200,

                data:
                    tenantCapabilitySuccess,
            });

            await firstLoad;

            expect(
                runtime.getState(),
            ).toEqual({
                status:
                    'ready',

                projection:
                    organizationCapabilitySuccess.data,
            });

            runtime.dispose();
        });

        it('treats explicit request cancellation as authority-neutral and returns to unresolved', async () => {
            const membership =
                createMembershipSource(
                    readyMembershipState(),
                );

            const workspace =
                createWorkspaceSource(
                    readyWorkspaceState(
                        tenantWorkspace,
                    ),
                );

            const operations:
                CapabilityProjectionOperations = {
                    async projectTenant(
                        _membershipId,
                        options,
                    ) {
                        return new Promise(
                            (
                                resolve,
                            ) => {
                                options
                                    ?.signal
                                    ?.addEventListener(
                                        'abort',
                                        () => {
                                            resolve({
                                                ok:
                                                    false,

                                                kind:
                                                    'aborted',

                                                cause:
                                                    new Error(
                                                        'aborted',
                                                    ),
                                            });
                                        },
                                        {
                                            once:
                                                true,
                                        },
                                    );
                            },
                        );
                    },

                    async projectWorkspace() {
                        return {
                            ok:
                                true,

                            status:
                                200,

                            data:
                                organizationCapabilitySuccess,
                        };
                    },
                };

            const runtime =
                createCapabilityRuntime(
                    operations,
                    membership.runtime,
                    workspace.runtime,
                );

            const controller =
                new AbortController();

            const load =
                runtime.bootstrap({
                    signal:
                        controller.signal,
                });

            controller.abort();

            await load;

            expect(
                runtime.getState(),
            ).toEqual({
                status:
                    'unresolved',
            });

            runtime.dispose();
        });

        it('does not load capabilities when Membership and Workspace canonical contexts disagree', async () => {
            const probe =
                createProbe();

            const membership =
                createMembershipSource(
                    readyMembershipState(),
                );

            const workspace =
                createWorkspaceSource({
                    status:
                        'ready',

                    context: {
                        ...context,

                        membership: {
                            ...context.membership,

                            id:
                                otherMembershipId,
                        },
                    },

                    current:
                        tenantWorkspace,
                });

            const runtime =
                createCapabilityRuntime(
                    createOperations(
                        probe,
                    ),
                    membership.runtime,
                    workspace.runtime,
                );

            await runtime.bootstrap();

            expect(
                runtime.getState(),
            ).toEqual({
                status:
                    'unresolved',
            });

            expect(
                probe.tenantMembershipIds,
            ).toEqual([]);

            expect(
                probe.workspaceRequests,
            ).toEqual([]);

            runtime.dispose();
        });

        it('dispose invalidates capability authority and detaches upstream subscriptions', async () => {
            const probe =
                createProbe();

            const membership =
                createMembershipSource(
                    readyMembershipState(),
                );

            const workspace =
                createWorkspaceSource(
                    readyWorkspaceState(
                        tenantWorkspace,
                    ),
                );

            const runtime =
                createCapabilityRuntime(
                    createOperations(
                        probe,
                    ),
                    membership.runtime,
                    workspace.runtime,
                );

            await runtime.bootstrap();

            runtime.dispose();

            expect(
                runtime.getState(),
            ).toEqual({
                status:
                    'unresolved',
            });

            workspace.setState(
                readyWorkspaceState(
                    organizationWorkspace,
                ),
            );

            await Promise.resolve();

            expect(
                probe.tenantMembershipIds,
            ).toHaveLength(
                1,
            );

            expect(
                probe.workspaceRequests,
            ).toEqual([]);
        });
    },
);
