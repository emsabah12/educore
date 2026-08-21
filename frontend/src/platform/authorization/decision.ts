import type {
    PermissionName,
} from '@/platform/authorization/contract';
import {
    createPermissionEvaluator,
    type PermissionEvaluator,
} from '@/platform/authorization/permission-evaluator';
import type {
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
