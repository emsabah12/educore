import {
    createCapabilityAuthFailurePropagationCoordinator,
} from '@/app/authorization/capability-auth-failure-propagation';
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
    createNoopObservabilityPort,
} from '@/platform/observability/runtime';
import type {
    ObservabilityPort,
} from '@/platform/observability/port';
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

    observability:
        ObservabilityPort;

    queryClient:
        ReturnType<
            typeof createAppQueryClient
        >;

    router:
        ReturnType<
            typeof createAppRouter
        >;
}

export function createApplicationRuntime():
    ApplicationRuntime {
    const observability =
        createNoopObservabilityPort();

    const reportApplicationRuntimeCoordinationFailure =
        (
            error:
                unknown,
        ): void => {
            /*
             * Cross-runtime recovery happens outside React
             * render and Effect execution, therefore an
             * ErrorBoundary cannot catch an asynchronous
             * coordination failure.
             *
             * Route the throwable through the canonical
             * observability port owned by this Application
             * runtime. Exception normalization, privacy
             * filtering, and provider failure isolation
             * remain platform responsibilities.
             */
            observability.captureException(
                'application_runtime_coordination_failed',
                error,
                {
                    module:
                        'application',
                },
            );
        };

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
     * CapabilityRuntime owns authorization projection
     * state while BrowserAuthRuntime remains the sole
     * semantic owner of authentication truth.
     *
     * Application composition therefore observes
     * Capability Browser API failures and forwards them
     * to the canonical Auth runtime without coupling the
     * platform runtimes directly.
     */
    const authenticationFailurePropagationCoordinator =
        createCapabilityAuthFailurePropagationCoordinator(
            capabilities,
            auth,
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

            authenticationFailurePropagationCoordinator
                .dispose();

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
        observability,

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
