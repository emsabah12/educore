import {
    Outlet,
} from 'react-router';

import {
    protectedApplicationPolicy,
} from '@/app/routing/application-route-access';
import {
    ProtectedRouteBoundary,
} from '@/app/routing/ProtectedRouteBoundary';

/*
 * Application-level protected shell boundary.
 *
 * Policy metadata is owned by the canonical application
 * route-access registry so routing and later navigation
 * projection cannot drift into separate authorization
 * vocabularies.
 */
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