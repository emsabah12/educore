import type {
    PermissionName,
} from '@/platform/authorization/contract';
import {
    createPermissionEvaluator,
    type PermissionEvaluator,
} from '@/platform/authorization/permission-evaluator';
import type {
    CapabilityProjectionData,
    CapabilityState,
    CapabilityStateFailure,
} from '@/platform/authorization/state';

export type PermissionRequirement =
    | {
        readonly mode:
            'single';

        readonly permission:
            PermissionName;
    }
    | {
        readonly mode:
            'all';

        readonly permissions:
            readonly PermissionName[];
    }
    | {
        readonly mode:
            'any';

        readonly permissions:
            readonly PermissionName[];
    }
    | {
        /*
         * §Kelola Anggota & Role — SATU-SATUNYA pengecualian dari
         * "exact canonical permission matching only" yang dinyatakan
         * eksplisit di permission-evaluator.ts. Ini BUKAN inferensi
         * role→permission sisi klien — murni membaca flag
         * is_tenant_admin yang SUDAH DIHITUNG backend (hasRole('admin'),
         * sama persis dengan pemeriksaan tenant.role:admin yang
         * menggerbang endpoint RBAC-management sesungguhnya). Cuma
         * berlaku untuk proyeksi TENANT — proyeksi WORKSPACE tidak
         * punya konsep ini sama sekali.
         */
        readonly mode:
            'tenant-admin';
    };

export interface PendingAuthorizationDecision {
    readonly status:
        'pending';

    /*
     * Preserve whether authority has not started resolving
     * yet or is actively loading.
     *
     * Both remain fail-closed for protected content, but
     * neither represents an authorization denial.
     */
    readonly capabilityStatus:
        | 'unresolved'
        | 'loading';
}

export interface UnavailableAuthorizationDecision {
    readonly status:
        'unavailable';

    readonly failure:
        CapabilityStateFailure;
}

export interface AllowedAuthorizationDecision {
    readonly status:
        'allowed';
}

export interface DeniedAuthorizationDecision {
    readonly status:
        'denied';
}

export type AuthorizationDecision =
    | PendingAuthorizationDecision
    | UnavailableAuthorizationDecision
    | AllowedAuthorizationDecision
    | DeniedAuthorizationDecision;

export interface AuthorizationDecisionEvaluator {
    evaluate(
        requirement:
            PermissionRequirement,
    ): AuthorizationDecision;
}

function evaluateReadyRequirement(
    evaluator:
        PermissionEvaluator,
    projection:
        CapabilityProjectionData,
    requirement:
        PermissionRequirement,
): boolean {
    switch (
        requirement.mode
    ) {
        case 'single':
            return evaluator.has(
                requirement.permission,
            );

        case 'all':
            return evaluator.hasAll(
                requirement.permissions,
            );

        case 'any':
            return evaluator.hasAny(
                requirement.permissions,
            );

        case 'tenant-admin':
            return 'is_tenant_admin' in projection
                && projection.is_tenant_admin;
    }
}

export function createAuthorizationDecisionEvaluator(
    state:
        CapabilityState,
): AuthorizationDecisionEvaluator {
    switch (
        state.status
    ) {
        case 'unresolved': {
            const decision:
                PendingAuthorizationDecision = {
                    status:
                        'pending',

                    capabilityStatus:
                        'unresolved',
                };

            return {
                evaluate() {
                    return decision;
                },
            };
        }

        case 'loading': {
            const decision:
                PendingAuthorizationDecision = {
                    status:
                        'pending',

                    capabilityStatus:
                        'loading',
                };

            return {
                evaluate() {
                    return decision;
                },
            };
        }

        case 'unavailable': {
            const decision:
                UnavailableAuthorizationDecision = {
                    status:
                        'unavailable',

                    failure:
                        state.failure,
                };

            return {
                evaluate() {
                    return decision;
                },
            };
        }

        case 'ready': {
            /*
             * Build the permission lookup snapshot once for
             * this READY capability state.
             *
             * Many navigation, route, and action decisions
             * can then share the same evaluator without
             * repeatedly scanning permissions[].
             */
            const permissionEvaluator =
                createPermissionEvaluator(
                    state.projection,
                );

            return {
                evaluate(
                    requirement,
                ) {
                    return evaluateReadyRequirement(
                        permissionEvaluator,
                        state.projection,
                        requirement,
                    )
                        ? {
                            status:
                                'allowed',
                        }
                        : {
                            status:
                                'denied',
                        };
                },
            };
        }
    }
}
