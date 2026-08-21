import {
    createContext,
    type PropsWithChildren,
    useContext,
    useEffect,
    useRef,
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
}

function useWorkspaceBootstrapLifecycleClassifier():
    WorkspaceBootstrapLifecycleClassifier {
    const classifierRef =
        useRef<
            WorkspaceBootstrapLifecycleClassifier
            | null
        >(null);

    if (
        classifierRef.current
            === null
    ) {
        classifierRef.current =
            createWorkspaceBootstrapLifecycleClassifier();
    }

    return classifierRef.current;
}

export function WorkspaceContextProvider({
    runtime,
    children,
}: WorkspaceContextProviderProps) {
    const authenticationState =
        useBrowserAuthState();

    const membershipState =
        useMembershipContextState();

    const lifecycleClassifier =
        useWorkspaceBootstrapLifecycleClassifier();

    useEffect(
        () => {
            /*
             * Observe every upstream lifecycle transition
             * before deciding whether Workspace may
             * bootstrap.
             *
             * In particular, authenticating,
             * resolving-context, and Membership switching
             * are needed to distinguish fresh context from
             * initial reload.
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
             * Workspace discovery is already in flight.
             *
             * WorkspaceRuntime supports replacing an
             * in-flight discovery through its operation
             * revision fence.
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

    return (
        <WorkspaceRuntimeContext.Provider
            value={runtime}
        >
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
