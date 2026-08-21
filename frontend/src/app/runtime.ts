import {
    createCapabilityWorkspaceRecoveryCoordinator,
} from '@/app/authorization/capability-workspace-recovery';
import { createAppQueryClient } from '@/app/query-client';
import { createAppRouter } from '@/app/router';
import {
    createBrowserApiClient,
    type BrowserApiClient,
} from '@/platform/api';
import {
    createBrowserAuthOperations,
    createBrowserAuthRuntime,
    type BrowserAuthRuntime,
} from '@/platform/auth';
import {
    createCapabilityProjectionOperations,
    createCapabilityRuntime,
    createWorkspaceCapabilityVerifier,
    type CapabilityRuntime,
} from '@/platform/authorization';
import {
    createMembershipContextOperations,
    createMembershipContextRuntime,
    type MembershipContextRuntime,
} from '@/platform/membership';
import {
    createWorkspaceContextOperations,
    createWorkspaceContextRuntime,
    type WorkspaceContextRuntime,
} from '@/platform/workspace';

export interface ApplicationRuntime {
    apiClient:
        BrowserApiClient;

    auth:
        BrowserAuthRuntime;

    membership:
        MembershipContextRuntime;

    workspace:
        WorkspaceContextRuntime;

    capabilities:
        CapabilityRuntime;

    queryClient:
        ReturnType<
            typeof createAppQueryClient
        >;

    router:
        ReturnType<
            typeof createAppRouter
        >;
}

function reportApplicationRuntimeCoordinationFailure(
    error:
        unknown,
): void {
    /*
     * Cross-runtime recovery happens outside React render
     * and Effect execution, therefore an ErrorBoundary
     * cannot catch an asynchronous coordination failure.
     *
     * Do not include authentication credentials,
     * membership payloads, or capability projections in
     * this diagnostic.
     */
    console.error(
        'EduCore application runtime coordination failed.',
        error,
    );
}

export function createApplicationRuntime():
    ApplicationRuntime {
    const apiClient =
        createBrowserApiClient();

    const auth =
        createBrowserAuthRuntime(
            createBrowserAuthOperations(
                apiClient,
            ),
        );

    const membership =
        createMembershipContextRuntime(
            createMembershipContextOperations(
                apiClient,
            ),
            auth,
        );

    /*
     * Capability projection operations are deliberately
     * shared by both the stateless Workspace verifier and
     * the active Capability runtime.
     *
     * This keeps the dependency graph acyclic:
     *
     * operations
     *   ├─> Workspace verifier
     *   │       ↓
     *   │   Workspace runtime
     *   │       ↓
     *   └────> Capability runtime
     */
    const capabilityOperations =
        createCapabilityProjectionOperations(
            apiClient,
        );

    const workspaceVerifier =
        createWorkspaceCapabilityVerifier(
            capabilityOperations,
        );

    const workspace =
        createWorkspaceContextRuntime(
            createWorkspaceContextOperations(
                apiClient,
            ),
            membership,
            workspaceVerifier,
        );

    const capabilities =
        createCapabilityRuntime(
            capabilityOperations,
            membership,
            workspace,
        );

    /*
     * CapabilityRuntime owns capability authority while
     * WorkspaceRuntime owns organizational recovery.
     *
     * Application composition is therefore the correct
     * boundary for connecting a canonical stale
     * capability failure to Workspace recovery.
     *
     * Aggregate disposal ownership is deliberately
     * deferred to FEI-8F.4C. CapabilityRuntime disposal
     * currently clears its subscribers, which also
     * detaches this coordinator in existing tests.
     */
    const recoveryCoordinator =
    createCapabilityWorkspaceRecoveryCoordinator(
        capabilities,
        workspace,
        {
            reportFailure:
                reportApplicationRuntimeCoordinationFailure,
        },
    );

    let disposed =
        false;

    const dispose =
        (): void => {
            if (
                disposed
            ) {
                return;
            }

            disposed =
                true;

            recoveryCoordinator
                .dispose();

            capabilities
                .dispose();

            workspace
                .dispose();
        };

    return {
        apiClient,
        auth,
        membership,
        workspace,
        capabilities,

        queryClient:
            createAppQueryClient(),

        router:
            createAppRouter(),

        dispose,
    };
}

export interface ApplicationRuntime {
    apiClient:
        BrowserApiClient;

    auth:
        BrowserAuthRuntime;

    membership:
        MembershipContextRuntime;

    workspace:
        WorkspaceContextRuntime;

    capabilities:
        CapabilityRuntime;

    queryClient:
        ReturnType<
            typeof createAppQueryClient
        >;

    router:
        ReturnType<
            typeof createAppRouter
        >;

    dispose():
        void;
}