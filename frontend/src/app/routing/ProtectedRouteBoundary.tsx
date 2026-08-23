import {
    type PropsWithChildren,
} from 'react';
import {
    Navigate,
    useLocation,
} from 'react-router';

import {
    useCapabilityRuntime,
} from '@/app/authorization/CapabilityContextProvider';
import {
    useBrowserAuthRuntime,
    useBrowserAuthState,
} from '@/app/auth/BrowserAuthProvider';
import {
    useMembershipContextRuntime,
} from '@/app/membership/MembershipContextProvider';
import {
    recoverProtectedRouteUnavailableSource,
} from '@/app/routing/protected-route-recovery';
import {
    ProtectedRouteStateView,
} from '@/app/routing/ProtectedRouteStateView';
import {
    useProtectedRouteAccess,
} from '@/app/routing/useProtectedRouteAccess';
import {
    useWorkspaceContextRuntime,
} from '@/app/workspace/WorkspaceContextProvider';
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

function reportProtectedRouteRecoveryFailure(
    _error:
        unknown,
): void {
    /*
     * Async recovery failures cannot be caught by a React
     * ErrorBoundary when they originate from an interaction
     * handler.
     *
     * Keep this diagnostic deliberately free of the raw
     * error object. Sensitive request, authentication, or
     * context detail must not be emitted from route UX.
     */
    console.error(
        'EduCore protected route recovery failed.',
    );
}

export function ProtectedRouteBoundary({
    policy,
    children,
}: ProtectedRouteBoundaryProps) {
    const decision =
        useProtectedRouteAccess(
            policy,
        );

    /*
     * Recovery dependencies are resolved unconditionally.
     *
     * React hooks must never be called only from the
     * unavailable branch even though the runtimes are used
     * only by controlled recovery.
     */
    const authenticationRuntime =
        useBrowserAuthRuntime();

    const authenticationState =
        useBrowserAuthState();

    const membershipRuntime =
        useMembershipContextRuntime();

    const workspaceRuntime =
        useWorkspaceContextRuntime();

    const capabilityRuntime =
        useCapabilityRuntime();

    const location =
        useLocation();

    function retryUnavailableRoute(): void {
        if (
            decision.status
                !== 'unavailable'
        ) {
            return;
        }

        /*
         * The coordinator owns source-specific recovery.
         *
         * Boundary composition only supplies the current
         * route source and provider-owned runtime
         * dependencies.
         */
        void recoverProtectedRouteUnavailableSource(
            decision.source,
            {
                authenticationStatus:
                    authenticationState.status,

                authentication:
                    authenticationRuntime,

                membership:
                    membershipRuntime,

                workspace:
                    workspaceRuntime,

                capabilities:
                    capabilityRuntime,

                reportFailure:
                    reportProtectedRouteRecoveryFailure,
            },
        );
    }

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

        case 'unavailable':
            return (
                <ProtectedRouteStateView
                    decision={decision}
                    onRetryUnavailable={
                        retryUnavailableRoute
                    }
                />
            );

        case 'pending':
        case 'membership-required':
        case 'membership-empty':
        case 'context-required':
        case 'denied':
            return (
                <ProtectedRouteStateView
                    decision={decision}
                />
            );
    }
}
