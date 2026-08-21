import {
    describe,
    expect,
    it,
} from 'vitest';

import {
    capabilityReducer,
    createInitialCapabilityState,
    type CapabilityState,
    type TenantCapabilityData,
    type WorkspaceCapabilityData,
} from '@/platform/authorization';

const membershipId =
    '018f3b6a-7c20-7abc-8def-1234567890ab';

const tenantId =
    '018f3b6a-7c20-7cde-8def-1234567890ab';

const organizationalAssignmentId =
    '018f3b6a-7c20-7def-9abc-1234567890ab';

const organizationId =
    '018f3b6a-7c20-7abc-9def-1234567890ab';

const tenantProjection:
    TenantCapabilityData = {
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
    };

const workspaceProjection:
    WorkspaceCapabilityData = {
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
            'academic.grades.write',
            'dormitory.rooms.manage',
        ],
    };

function readyTenantState():
    CapabilityState {
    return capabilityReducer(
        capabilityReducer(
            createInitialCapabilityState(),
            {
                type:
                    'LOAD_STARTED',
            },
        ),
        {
            type:
                'PROJECTION_ACCEPTED',

            projection:
                tenantProjection,
        },
    );
}

describe(
    'Capability state',
    () => {
        it('starts unresolved without capability authority', () => {
            expect(
                createInitialCapabilityState(),
            ).toEqual({
                status:
                    'unresolved',
            });
        });

        it('enters loading without preserving prior capability authority', () => {
            const ready =
                readyTenantState();

            expect(
                ready.status,
            ).toBe(
                'ready',
            );

            const loading =
                capabilityReducer(
                    ready,
                    {
                        type:
                            'LOAD_STARTED',
                    },
                );

            expect(
                loading,
            ).toEqual({
                status:
                    'loading',
            });

            expect(
                'projection'
                in loading,
            ).toBe(false);
        });

        it('accepts a validated TENANT capability projection only from loading', () => {
            const loading =
                capabilityReducer(
                    createInitialCapabilityState(),
                    {
                        type:
                            'LOAD_STARTED',
                    },
                );

            expect(
                capabilityReducer(
                    loading,
                    {
                        type:
                            'PROJECTION_ACCEPTED',

                        projection:
                            tenantProjection,
                    },
                ),
            ).toEqual({
                status:
                    'ready',

                projection:
                    tenantProjection,
            });
        });

        it('accepts a validated organizational Workspace capability projection only from loading', () => {
            const loading =
                capabilityReducer(
                    createInitialCapabilityState(),
                    {
                        type:
                            'LOAD_STARTED',
                    },
                );

            expect(
                capabilityReducer(
                    loading,
                    {
                        type:
                            'PROJECTION_ACCEPTED',

                        projection:
                            workspaceProjection,
                    },
                ),
            ).toEqual({
                status:
                    'ready',

                projection:
                    workspaceProjection,
            });
        });

        it('fails closed when capability transport becomes unavailable', () => {
            const loading =
                capabilityReducer(
                    createInitialCapabilityState(),
                    {
                        type:
                            'LOAD_STARTED',
                    },
                );

            const failure = {
                ok:
                    false as const,

                kind:
                    'network' as const,

                cause:
                    new Error(
                        'offline',
                    ),
            };

            expect(
                capabilityReducer(
                    loading,
                    {
                        type:
                            'LOAD_FAILED',

                        failure,
                    },
                ),
            ).toEqual({
                status:
                    'unavailable',

                failure,
            });
        });

        it('fails closed on structurally invalid capability projection semantics', () => {
            const loading =
                capabilityReducer(
                    createInitialCapabilityState(),
                    {
                        type:
                            'LOAD_STARTED',
                    },
                );

            const failure = {
                ok:
                    false as const,

                kind:
                    'invalid-payload' as const,
            };

            expect(
                capabilityReducer(
                    loading,
                    {
                        type:
                            'LOAD_FAILED',

                        failure,
                    },
                ),
            ).toEqual({
                status:
                    'unavailable',

                failure,
            });
        });

        it('fails closed on canonical capability scope mismatch', () => {
            const loading =
                capabilityReducer(
                    createInitialCapabilityState(),
                    {
                        type:
                            'LOAD_STARTED',
                    },
                );

            const failure = {
                ok:
                    false as const,

                kind:
                    'scope-mismatch' as const,
            };

            expect(
                capabilityReducer(
                    loading,
                    {
                        type:
                            'LOAD_FAILED',

                        failure,
                    },
                ),
            ).toEqual({
                status:
                    'unavailable',

                failure,
            });
        });

        it('rejects capability publication without an active load', () => {
            expect(
                () =>
                    capabilityReducer(
                        readyTenantState(),
                        {
                            type:
                                'PROJECTION_ACCEPTED',

                            projection:
                                tenantProjection,
                        },
                    ),
            ).toThrow(
                'Invalid EduCore Capability transition: ready -> PROJECTION_ACCEPTED',
            );
        });

        it('rejects failure publication without an active load', () => {
            expect(
                () =>
                    capabilityReducer(
                        createInitialCapabilityState(),
                        {
                            type:
                                'LOAD_FAILED',

                            failure: {
                                ok:
                                    false,

                                kind:
                                    'invalid-payload',
                            },
                        },
                    ),
            ).toThrow(
                'Invalid EduCore Capability transition: unresolved -> LOAD_FAILED',
            );
        });

        it('reset removes all current capability authority', () => {
            expect(
                capabilityReducer(
                    readyTenantState(),
                    {
                        type:
                            'RESET',
                    },
                ),
            ).toEqual({
                status:
                    'unresolved',
            });
        });
    },
);
