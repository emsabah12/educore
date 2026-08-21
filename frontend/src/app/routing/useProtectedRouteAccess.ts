import {
    useMemo,
} from 'react';

import {
    useBrowserAuthState,
} from '@/app/auth/BrowserAuthProvider';
import {
    useCapabilityState,
} from '@/app/authorization/CapabilityContextProvider';
import {
    useMembershipContextState,
} from '@/app/membership/MembershipContextProvider';
import {
    useWorkspaceContextState,
} from '@/app/workspace/WorkspaceContextProvider';
import {
    evaluateProtectedRouteAccess,
    type ProtectedRouteAccessDecision,
    type ProtectedRoutePolicy,
} from '@/platform/routing';

export function useProtectedRouteAccess(
    policy:
        ProtectedRoutePolicy,
): ProtectedRouteAccessDecision {
    const authentication =
        useBrowserAuthState();

    const membership =
        useMembershipContextState();

    const workspace =
        useWorkspaceContextState();

    const capability =
        useCapabilityState();

    /*
     * Runtime Providers own lifecycle orchestration.
     *
     * This hook only combines already-published canonical
     * snapshots with immutable route policy metadata.
     * It must not bootstrap, refresh, reset, switch context,
     * or otherwise manufacture route authority.
     */
    return useMemo(
        () =>
            evaluateProtectedRouteAccess({
                policy,
                authentication,
                membership,
                workspace,
                capability,
            }),
        [
            policy,
            authentication,
            membership,
            workspace,
            capability,
        ],
    );
}
