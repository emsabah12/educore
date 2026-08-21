import {
    type PropsWithChildren,
} from 'react';
import {
    Navigate,
    useLocation,
} from 'react-router';

import {
    ProtectedRouteStateView,
} from '@/app/routing/ProtectedRouteStateView';
import {
    useProtectedRouteAccess,
} from '@/app/routing/useProtectedRouteAccess';
import {
    parseSafeInternalReturnDestination,
    type ProtectedRoutePolicy,
} from '@/platform/routing';

export interface ProtectedRouteBoundaryProps
    extends PropsWithChildren {
    readonly policy:
        ProtectedRoutePolicy;
}

function createCurrentInternalLocation(
    pathname:
        string,
    search:
        string,
    hash:
        string,
): string {
    return `${pathname}${search}${hash}`;
}

export function ProtectedRouteBoundary({
    policy,
    children,
}: ProtectedRouteBoundaryProps) {
    const decision =
        useProtectedRouteAccess(
            policy,
        );

    const location =
        useLocation();

    switch (
        decision.status
    ) {
        case 'allowed':
            return (
                <>
                    {children}
                </>
            );

        case 'unauthenticated': {
            /*
             * React Router publishes the current application
             * location, but navigation input still passes
             * through the canonical routing-security
             * primitive before becoming a login return hint.
             */
            const returnDestination =
                parseSafeInternalReturnDestination(
                    createCurrentInternalLocation(
                        location.pathname,
                        location.search,
                        location.hash,
                    ),
                );

            const loginDestination =
                returnDestination === null
                    ? '/login'
                    : `/login?returnTo=${encodeURIComponent(
                        returnDestination,
                    )}`;

            return (
                <Navigate
                    replace
                    to={loginDestination}
                />
            );
        }

        case 'pending':
        case 'membership-required':
        case 'membership-empty':
        case 'unavailable':
        case 'context-required':
        case 'denied':
            return (
                <ProtectedRouteStateView
                    decision={decision}
                />
            );
    }
}
