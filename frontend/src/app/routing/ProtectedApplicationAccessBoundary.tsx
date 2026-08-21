import {
    Outlet,
} from 'react-router';

import {
    ProtectedRouteBoundary,
} from '@/app/routing/ProtectedRouteBoundary';
import {
    defineProtectedRoutePolicy,
} from '@/platform/routing';

/*
 * Application-level protected shell policy.
 *
 * Business routes will contribute their own explicit
 * permission policies later. This policy establishes only
 * the canonical authenticated Membership/Workspace
 * application boundary.
 */
const protectedApplicationPolicy =
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

export function ProtectedApplicationAccessBoundary() {
    return (
        <ProtectedRouteBoundary
            policy={
                protectedApplicationPolicy
            }
        >
            <Outlet />
        </ProtectedRouteBoundary>
    );
}
