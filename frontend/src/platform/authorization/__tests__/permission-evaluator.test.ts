import {
    describe,
    expect,
    it,
} from 'vitest';

import {
    createPermissionEvaluator,
    type CapabilityProjectionData,
} from '@/platform/authorization';

const studentsView =
    'academic.students.view';

const gradesWrite =
    'academic.grades.write';

const roomsManage =
    'dormitory.rooms.manage';

function createTenantProjection(
    permissions:
        string[],
    isGlobalSuperadmin =
        false,
): CapabilityProjectionData {
    return {
        scope: {
            type:
                'tenant',

            tenant_id:
                '018f3b6a-7c20-7abc-8def-1234567890ab',

            membership_id:
                '018f3b6a-7c20-7bcd-8def-1234567890ab',
        },

        is_global_superadmin:
            isGlobalSuperadmin,

        permissions,
    };
}

function createWorkspaceProjection(
    permissions:
        string[],
): CapabilityProjectionData {
    return {
        scope: {
            type:
                'organization',

            tenant_id:
                '018f3b6a-7c20-7abc-8def-1234567890ab',

            membership_id:
                '018f3b6a-7c20-7bcd-8def-1234567890ab',

            organizational_assignment_id:
                '018f3b6a-7c20-7cde-8def-1234567890ab',

            organization_id:
                '018f3b6a-7c20-7def-8def-1234567890ab',

            organization_unit_id:
                null,
        },

        is_global_superadmin:
            false,

        permissions,
    };
}

describe(
    'Permission evaluator',
    () => {
        it('matches canonical permission names exactly', () => {
            const evaluator =
                createPermissionEvaluator(
                    createTenantProjection([
                        studentsView,
                        gradesWrite,
                    ]),
                );

            expect(
                evaluator.has(
                    studentsView,
                ),
            ).toBe(
                true,
            );

            expect(
                evaluator.has(
                    'academic.students',
                ),
            ).toBe(
                false,
            );

            expect(
                evaluator.has(
                    'academic.*',
                ),
            ).toBe(
                false,
            );
        });

        it('requires every permission for hasAll', () => {
            const evaluator =
                createPermissionEvaluator(
                    createTenantProjection([
                        studentsView,
                        gradesWrite,
                    ]),
                );

            expect(
                evaluator.hasAll([
                    studentsView,
                    gradesWrite,
                ]),
            ).toBe(
                true,
            );

            expect(
                evaluator.hasAll([
                    studentsView,
                    roomsManage,
                ]),
            ).toBe(
                false,
            );
        });

        it('requires at least one permission for hasAny', () => {
            const evaluator =
                createPermissionEvaluator(
                    createTenantProjection([
                        studentsView,
                    ]),
                );

            expect(
                evaluator.hasAny([
                    roomsManage,
                    studentsView,
                ]),
            ).toBe(
                true,
            );

            expect(
                evaluator.hasAny([
                    roomsManage,
                    gradesWrite,
                ]),
            ).toBe(
                false,
            );
        });

        it('uses explicit empty requirement semantics', () => {
            const evaluator =
                createPermissionEvaluator(
                    createTenantProjection([]),
                );

            expect(
                evaluator.hasAll([]),
            ).toBe(
                true,
            );

            expect(
                evaluator.hasAny([]),
            ).toBe(
                false,
            );
        });

        it('does not treat is_global_superadmin as a permission bypass', () => {
            const evaluator =
                createPermissionEvaluator(
                    createTenantProjection(
                        [],
                        true,
                    ),
                );

            expect(
                evaluator.has(
                    studentsView,
                ),
            ).toBe(
                false,
            );

            expect(
                evaluator.hasAll([
                    studentsView,
                ]),
            ).toBe(
                false,
            );

            expect(
                evaluator.hasAny([
                    studentsView,
                ]),
            ).toBe(
                false,
            );
        });

        it('uses the same permission semantics for organizational capability projections', () => {
            const evaluator =
                createPermissionEvaluator(
                    createWorkspaceProjection([
                        roomsManage,
                    ]),
                );

            expect(
                evaluator.has(
                    roomsManage,
                ),
            ).toBe(
                true,
            );

            expect(
                evaluator.has(
                    studentsView,
                ),
            ).toBe(
                false,
            );
        });

        it('takes a permission snapshot instead of observing later projection mutation', () => {
            const projection =
                createTenantProjection([
                    studentsView,
                ]);

            const evaluator =
                createPermissionEvaluator(
                    projection,
                );

            projection.permissions.push(
                gradesWrite,
            );

            expect(
                evaluator.has(
                    studentsView,
                ),
            ).toBe(
                true,
            );

            expect(
                evaluator.has(
                    gradesWrite,
                ),
            ).toBe(
                false,
            );
        });

        it('does not let unrelated future backend permissions grant known frontend requirements', () => {
            const evaluator =
                createPermissionEvaluator(
                    createTenantProjection([
                        'future.module.permission',
                    ]),
                );

            expect(
                evaluator.has(
                    studentsView,
                ),
            ).toBe(
                false,
            );
        });
    },
);
