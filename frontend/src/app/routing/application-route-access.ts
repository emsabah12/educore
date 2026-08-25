import {
    defineProtectedRoutePolicy,
    type ProtectedRoutePolicy,
} from '@/platform/routing';

/*
 * Canonical authenticated application boundary.
 *
 * This policy remains distinct from a concrete business
 * route policy. It establishes the minimum verified
 * Tenant-context boundary inherited by the current root
 * application destination.
 */
export const protectedApplicationPolicy =
    defineProtectedRoutePolicy({
        routeId:
            'app.protected-root',

        contextRequirement:
            'tenant',

        authorizationScope:
            'tenant',

        requiredPermissions:
            null,
    });

/*
 * Keys are stable React Router route identities.
 *
 * Values are the canonical effective access policies for
 * those registered application destinations.
 *
 * Navigation consumers may resolve policy through this
 * adapter but must never duplicate policy metadata.
 */
const applicationRouteAccessPolicies =
    Object.freeze({
        root:
            protectedApplicationPolicy,
    }) satisfies Readonly<
        Record<
            string,
            ProtectedRoutePolicy
        >
    >;

export function resolveApplicationRouteAccessPolicy(
    routeId:
        string,
): ProtectedRoutePolicy | null {
    if (
        ! Object.prototype.hasOwnProperty.call(
            applicationRouteAccessPolicies,
            routeId,
        )
    ) {
        return null;
    }

    return applicationRouteAccessPolicies[
        routeId as keyof typeof applicationRouteAccessPolicies
    ];
}
