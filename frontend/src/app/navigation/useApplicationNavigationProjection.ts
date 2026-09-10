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
    useTenantEffectiveFeaturesQuery,
} from '@/app/navigation/api/use-tenant-effective-features-query';
import {
    projectApplicationNavigation,
    type ApplicationNavigationProjection,
} from '@/app/navigation/navigation-projection';
import {
    useWorkspaceContextState,
} from '@/app/workspace/WorkspaceContextProvider';

export function useApplicationNavigationProjection():
    readonly ApplicationNavigationProjection[] {
    const authentication =
        useBrowserAuthState();

    const membership =
        useMembershipContextState();

    const workspace =
        useWorkspaceContextState();

    const capability =
        useCapabilityState();

    /*
     * `.data` stays `undefined` while pending/erroring — the
     * projection treats that as "no features available"
     * (fail closed), never as "all features available".
     */
    const effectiveFeatureCodes =
        useTenantEffectiveFeaturesQuery().data;

    /*
     * Runtime Providers own all lifecycle orchestration.
     *
     * This hook only adapts already-published canonical
     * snapshots into the pure navigation projection.
     *
     * It must never bootstrap, refresh, discover, switch
     * context, or otherwise manufacture authority.
     */
    return useMemo(
        () =>
            projectApplicationNavigation({
                authentication,
                membership,
                workspace,
                capability,
                effectiveFeatureCodes,
            }),
        [
            authentication,
            membership,
            workspace,
            capability,
            effectiveFeatureCodes,
        ],
    );
}
