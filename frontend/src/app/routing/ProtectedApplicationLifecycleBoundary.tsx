import {
    Outlet,
} from 'react-router';

import {
    WorkspaceContextLifecycle,
} from '@/app/workspace/WorkspaceContextProvider';

/*
 * This boundary activates protected-application runtime
 * lifecycle without owning the long-lived runtimes.
 *
 * Route navigation may mount/unmount this component, but
 * ApplicationRuntime construction and disposal remain
 * application-owned.
 */
export function ProtectedApplicationLifecycleBoundary() {
    return (
        <>
            <WorkspaceContextLifecycle />

            <Outlet />
        </>
    );
}
