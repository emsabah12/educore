import {
    createContext,
    type PropsWithChildren,
    useContext,
    useEffect,
    useState,
    useSyncExternalStore,
} from 'react';

import {
    useBrowserAuthState,
} from '@/app/auth/BrowserAuthProvider';
import {
    useMembershipContextState,
} from '@/app/membership/MembershipContextProvider';
import {
    createWorkspaceBootstrapLifecycleClassifier,
    createWorkspaceMembershipLifecycleObservation,
    type WorkspaceBootstrapLifecycleClassifier,
} from '@/app/workspace/lifecycle';
import type {
    WorkspaceContextRuntime,
    WorkspaceContextState,
} from '@/platform/workspace';

const WorkspaceRuntimeContext =
    createContext<
        WorkspaceContextRuntime | null
    >(null);

export interface WorkspaceContextProviderProps
    extends PropsWithChildren {
    readonly runtime:
        WorkspaceContextRuntime;

    /*
     * Existing standalone Provider consumers keep the
     * established lifecycle behavior by default.
     *
     * Application composition may disable it when route
     * ownership activates Workspace lifecycle explicitly.
     */
    readonly activateLifecycle?:
        boolean;
}

function useWorkspaceBootstrapLifecycleClassifier():
    WorkspaceBootstrapLifecycleClassifier {
    const [
        lifecycleClassifier,
    ] = useState(
        () =>
            createWorkspaceBootstrapLifecycleClassifier(),
    );

    return lifecycleClassifier;
}

export function WorkspaceContextLifecycle() {
    const authenticationState =
        useBrowserAuthState();

    const membershipState =
        useMembershipContextState();

    const runtime =
        useWorkspaceContextRuntime();

    const lifecycleClassifier =
        useWorkspaceBootstrapLifecycleClassifier();

    useEffect(
        () => {
            /*
             * Observe every upstream lifecycle transition
             * before deciding whether Workspace may
             * bootstrap.
             *
             * This lifecycle component may be route-scoped,
             * while WorkspaceRuntime itself remains a
             * long-lived application-owned object.
             */
            const decision =
                lifecycleClassifier.observe(
                    authenticationState.status,
                    createWorkspaceMembershipLifecycleObservation(
                        membershipState,
                    ),
                );

            if (
                decision
                    === null
            ) {
                return;
            }

            /*
             * Workspace authority may only be established
             * after canonical authentication and canonical
             * Membership/Tenant truth are both ready.
             */
            if (
                authenticationState.status
                    !== 'authenticated'
                || membershipState.status
                    !== 'ready'
            ) {
                return;
            }

            const workspaceStatus =
                runtime.getState().status;

            /*
             * StrictMode may clean up the first Effect while
             * discovery is already in flight.
             *
             * Stable READY/UNAVAILABLE states are not
             * automatically reloaded by React.
             */
            if (
                workspaceStatus
                    !== 'unresolved'
                && workspaceStatus
                    !== 'discovering'
            ) {
                return;
            }

            const controller =
                new AbortController();

            void runtime.bootstrap({
                signal:
                    controller.signal,

                restoreHint:
                    decision.restoreHint,
            });

            return () => {
                controller.abort();
            };
        },
        [
            authenticationState.status,
            lifecycleClassifier,
            membershipState,
            runtime,
        ],
    );

    return null;
}

export function WorkspaceContextProvider({
    runtime,
    activateLifecycle = true,
    children,
}: WorkspaceContextProviderProps) {
    return (
        <WorkspaceRuntimeContext.Provider
            value={runtime}
        >
            {activateLifecycle
                ? (
                    <WorkspaceContextLifecycle />
                )
                : null}

            {children}
        </WorkspaceRuntimeContext.Provider>
    );
}

export function useWorkspaceContextRuntime():
    WorkspaceContextRuntime {
    const runtime =
        useContext(
            WorkspaceRuntimeContext,
        );

    if (
        runtime
            === null
    ) {
        throw new Error(
            'EduCore WorkspaceContext hooks require WorkspaceContextProvider.',
        );
    }

    return runtime;
}

export function useWorkspaceContextState():
    WorkspaceContextState {
    const runtime =
        useWorkspaceContextRuntime();

    return useSyncExternalStore(
        runtime.subscribe,
        runtime.getState,
    );
}
