import type {
    PermissionRequirement,
} from '@/platform/authorization';

export type RouteContextRequirement =
    | 'tenant'
    | 'organizational';

export type RouteAuthorizationScope =
    | 'tenant'
    | 'workspace';

/*
 * Public routes do not require a ProtectedRoutePolicy.
 *
 * This contract describes only routes that participate in
 * the authenticated application policy boundary.
 */
export interface ProtectedRoutePolicy {
    /*
     * Stable module/application-owned identity.
     *
     * Route IDs are not translated labels and must remain
     * stable across presentation changes.
     */
    readonly routeId:
        string;

    /*
     * "tenant"
     *     Route may operate without an organizational
     *     Workspace requirement.
     *
     * "organizational"
     *     Route requires an explicitly selected
     *     organizational Workspace.
     *
     * This contract deliberately does not invent separate
     * ORGANIZATION vs ORGANIZATION_UNIT requirements yet.
     */
    readonly contextRequirement:
        RouteContextRequirement;

    /*
     * Which canonical Capability projection family must be
     * used by the later route evaluator.
     *
     * This metadata never performs Capability loading.
     */
    readonly authorizationScope:
        RouteAuthorizationScope;

    /*
     * null represents a protected/context-aware route that
     * has no additional permission requirement.
     *
     * Otherwise the route reuses the canonical explicit
     * single/all/any PermissionRequirement semantics.
     */
    readonly requiredPermissions:
        PermissionRequirement | null;
}

function snapshotPermissionRequirement(
    requirement:
        PermissionRequirement,
): PermissionRequirement {
    switch (
        requirement.mode
    ) {
        case 'single':
            return {
                mode:
                    'single',

                permission:
                    requirement.permission,
            };

        case 'all':
            return {
                mode:
                    'all',

                permissions: [
                    ...requirement.permissions,
                ],
            };

        case 'any':
            return {
                mode:
                    'any',

                permissions: [
                    ...requirement.permissions,
                ],
            };
    }
}

export function defineProtectedRoutePolicy(
    policy:
        ProtectedRoutePolicy,
): ProtectedRoutePolicy {
    if (
        policy.routeId
            .trim()
            .length
            === 0
    ) {
        throw new Error(
            'EduCore protected route policy requires a non-empty stable routeId.',
        );
    }

    return {
        routeId:
            policy.routeId,

        contextRequirement:
            policy.contextRequirement,

        authorizationScope:
            policy.authorizationScope,

        requiredPermissions:
            policy.requiredPermissions
                === null
                ? null
                : snapshotPermissionRequirement(
                    policy.requiredPermissions,
                ),
    };
}
