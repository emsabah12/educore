import type {
    BrowserAuthState,
} from '@/platform/auth';
import type {
    CapabilityState,
} from '@/platform/authorization';
import {
    applicationNavigationCatalog,
    type ApplicationNavigationDefinition,
} from '@/platform/navigation';
import type {
    MembershipContextState,
} from '@/platform/membership';
import {
    evaluateProtectedRouteAccess,
    type ProtectedRouteAccessDecision,
    type ProtectedRoutePolicy,
} from '@/platform/routing';
import type {
    WorkspaceContextState,
} from '@/platform/workspace';

import {
    resolveApplicationRouteAccessPolicy,
} from '@/app/routing/application-route-access';

export type HiddenApplicationNavigationReason =
    | 'route-policy-missing'
    | 'authority-pending'
    | 'unauthenticated'
    | 'membership-required'
    | 'membership-empty'
    | 'authority-unavailable'
    | 'context-required'
    | 'permission-denied'
    | 'feature-unavailable';

export interface VisibleApplicationNavigationProjection {
    readonly status:
        'visible';

    readonly navigation:
        ApplicationNavigationDefinition;
}

export interface HiddenApplicationNavigationProjection {
    readonly status:
        'hidden';

    readonly navigation:
        ApplicationNavigationDefinition;

    readonly reason:
        HiddenApplicationNavigationReason;
}

export type ApplicationNavigationProjection =
    | VisibleApplicationNavigationProjection
    | HiddenApplicationNavigationProjection;

export type ApplicationRouteAccessPolicyResolver =
    (
        routeId:
            string,
    ) => ProtectedRoutePolicy | null;

export interface NavigationAuthoritySnapshot {
    readonly authentication:
        BrowserAuthState;

    readonly membership:
        MembershipContextState;

    readonly workspace:
        WorkspaceContextState;

    readonly capability:
        CapabilityState;

    /*
     * Tenant-wide Subscription feature codes (see
     * TenantSubscriptionService::effectiveFeatureCodes on the
     * backend), NOT an RBAC authority snapshot — "does the
     * tenant's plan/add-ons include this module at all" is a
     * different question from "can this Membership use it",
     * which `capability` above already answers. Bundled here
     * purely for threading convenience.
     *
     * `undefined` means the features query has not resolved
     * yet — callers must treat that as "no features
     * available" (fail closed), never as "all features
     * available".
     */
    readonly effectiveFeatureCodes:
        readonly string[] | undefined;
}

export interface NavigationProjectionInput
    extends NavigationAuthoritySnapshot {
    readonly definitions:
        readonly ApplicationNavigationDefinition[];

    readonly resolvePolicy:
        ApplicationRouteAccessPolicyResolver;
}

function hiddenReasonFromDecision(
    decision:
        Exclude<ProtectedRouteAccessDecision,
            {
                readonly status:
                    'allowed';
            }
        >,
): HiddenApplicationNavigationReason {
    switch (
        decision.status
    ) {
        case 'pending':
            return 'authority-pending';

        case 'unauthenticated':
            return 'unauthenticated';

        case 'membership-required':
            return 'membership-required';

        case 'membership-empty':
            return 'membership-empty';

        case 'unavailable':
            return 'authority-unavailable';

        case 'context-required':
            return 'context-required';

        case 'denied':
            return 'permission-denied';
    }
}

export function projectNavigationDefinitions(
    input:
        NavigationProjectionInput,
): readonly ApplicationNavigationProjection[] {
    const {
        definitions,
        resolvePolicy,
        authentication,
        membership,
        workspace,
        capability,
        effectiveFeatureCodes,
    } = input;

    const projections =
        definitions.map(
            (
                navigation,
            ): ApplicationNavigationProjection => {
                const policy =
                    resolvePolicy(
                        navigation.routeId,
                    );

                /*
                 * A navigation destination without a canonical
                 * application access policy must fail closed.
                 */
                if (
                    policy === null
                ) {
                    return Object.freeze({
                        status:
                            'hidden',

                        navigation,

                        reason:
                            'route-policy-missing',
                    });
                }

                /*
                 * Reuse the exact same pure route evaluator
                 * used by the protected routing boundary.
                 *
                 * Navigation must never manufacture a second
                 * authorization decision model.
                 */
                const decision =
                    evaluateProtectedRouteAccess({
                        policy,
                        authentication,
                        membership,
                        workspace,
                        capability,
                    });

                if (
                    decision.status
                        === 'allowed'
                ) {
                    if (
                        navigation.requiredFeature !== undefined
                        && ! (
                            effectiveFeatureCodes
                            ?? []
                        ).includes(
                            navigation.requiredFeature,
                        )
                    ) {
                        return Object.freeze({
                            status:
                                'hidden',

                            navigation,

                            reason:
                                'feature-unavailable' as const,
                        });
                    }

                    return Object.freeze({
                        status:
                            'visible',

                        navigation,
                    });
                }

                return Object.freeze({
                    status:
                        'hidden',

                    navigation,

                    reason:
                        hiddenReasonFromDecision(
                            decision,
                        ),
                });
            },
        );

    return Object.freeze(
        projections,
    );
}

export function projectApplicationNavigation(
    authority:
        NavigationAuthoritySnapshot,
): readonly ApplicationNavigationProjection[] {
    return projectNavigationDefinitions({
        definitions:
            applicationNavigationCatalog,

        resolvePolicy:
            resolveApplicationRouteAccessPolicy,

        authentication:
            authority.authentication,

        membership:
            authority.membership,

        workspace:
            authority.workspace,

        capability:
            authority.capability,

        effectiveFeatureCodes:
            authority.effectiveFeatureCodes,
    });
}
