import {
    describe,
    expect,
    it,
} from 'vitest';

import {
    createAuthorizationDecisionEvaluator,
    type CapabilityProjectionData,
    type CapabilityState,
    type PermissionRequirement,
} from '@/platform/authorization';

const studentsView =
    'academic.students.view';

const gradesWrite =
    'academic.grades.write';

const roomsManage =
    'dormitory.rooms.manage';

const studentsRequirement:
    PermissionRequirement = {
        mode:
            'single',

        permission:
            studentsView,
    };

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

function readyState(
    permissions:
        string[],
    isGlobalSuperadmin =
        false,
): CapabilityState {
    return {
        status:
            'ready',

        projection:
            createTenantProjection(
                permissions,
                isGlobalSuperadmin,
            ),
    };
}

describe(
    'Authorization decision evaluator',
    () => {
        it('keeps unresolved capability authority pending instead of denying access', () => {
            const evaluator =
                createAuthorizationDecisionEvaluator({
                    status:
                        'unresolved',
                });

            expect(
                evaluator.evaluate(
                    studentsRequirement,
                ),
            ).toEqual({
                status:
                    'pending',

                capabilityStatus:
                    'unresolved',
            });
        });

        it('keeps loading capability authority pending instead of denying access', () => {
            const evaluator =
                createAuthorizationDecisionEvaluator({
                    status:
                        'loading',
                });

            expect(
                evaluator.evaluate(
                    studentsRequirement,
                ),
            ).toEqual({
                status:
                    'pending',

                capabilityStatus:
                    'loading',
            });
        });

        it('preserves unavailable capability failure instead of converting it to permission denial', () => {
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

            const evaluator =
                createAuthorizationDecisionEvaluator({
                    status:
                        'unavailable',

                    failure,
                });

            expect(
                evaluator.evaluate(
                    studentsRequirement,
                ),
            ).toEqual({
                status:
                    'unavailable',

                failure,
            });
        });

        it('allows a ready capability with the exact required permission', () => {
            const evaluator =
                createAuthorizationDecisionEvaluator(
                    readyState([
                        studentsView,
                    ]),
                );

            expect(
                evaluator.evaluate(
                    studentsRequirement,
                ),
            ).toEqual({
                status:
                    'allowed',
            });
        });

        it('denies a ready capability when the exact required permission is absent', () => {
            const evaluator =
                createAuthorizationDecisionEvaluator(
                    readyState([
                        gradesWrite,
                    ]),
                );

            expect(
                evaluator.evaluate(
                    studentsRequirement,
                ),
            ).toEqual({
                status:
                    'denied',
            });
        });

        it('supports ALL permission requirements with explicit empty semantics', () => {
            const evaluator =
                createAuthorizationDecisionEvaluator(
                    readyState([
                        studentsView,
                        gradesWrite,
                    ]),
                );

            expect(
                evaluator.evaluate({
                    mode:
                        'all',

                    permissions: [
                        studentsView,
                        gradesWrite,
                    ],
                }),
            ).toEqual({
                status:
                    'allowed',
            });

            expect(
                evaluator.evaluate({
                    mode:
                        'all',

                    permissions: [
                        studentsView,
                        roomsManage,
                    ],
                }),
            ).toEqual({
                status:
                    'denied',
            });

            expect(
                evaluator.evaluate({
                    mode:
                        'all',

                    permissions:
                        [],
                }),
            ).toEqual({
                status:
                    'allowed',
            });
        });

        it('supports ANY permission requirements with explicit empty semantics', () => {
            const evaluator =
                createAuthorizationDecisionEvaluator(
                    readyState([
                        studentsView,
                    ]),
                );

            expect(
                evaluator.evaluate({
                    mode:
                        'any',

                    permissions: [
                        roomsManage,
                        studentsView,
                    ],
                }),
            ).toEqual({
                status:
                    'allowed',
            });

            expect(
                evaluator.evaluate({
                    mode:
                        'any',

                    permissions: [
                        roomsManage,
                        gradesWrite,
                    ],
                }),
            ).toEqual({
                status:
                    'denied',
            });

            expect(
                evaluator.evaluate({
                    mode:
                        'any',

                    permissions:
                        [],
                }),
            ).toEqual({
                status:
                    'denied',
            });
        });

        it('never treats global superadmin metadata as an authorization bypass', () => {
            const evaluator =
                createAuthorizationDecisionEvaluator(
                    readyState(
                        [],
                        true,
                    ),
                );

            expect(
                evaluator.evaluate(
                    studentsRequirement,
                ),
            ).toEqual({
                status:
                    'denied',
            });
        });

        it('retains the READY permission snapshot used when the decision evaluator was created', () => {
            const state =
                readyState([
                    studentsView,
                ]);

            if (
                state.status
                    !== 'ready'
            ) {
                throw new Error(
                    'Expected READY capability fixture.',
                );
            }

            const evaluator =
                createAuthorizationDecisionEvaluator(
                    state,
                );

            state.projection
                .permissions
                .push(
                    gradesWrite,
                );

            expect(
                evaluator.evaluate(
                    studentsRequirement,
                ),
            ).toEqual({
                status:
                    'allowed',
            });

            expect(
                evaluator.evaluate({
                    mode:
                        'single',

                    permission:
                        gradesWrite,
                }),
            ).toEqual({
                status:
                    'denied',
            });
        });
    },
);
