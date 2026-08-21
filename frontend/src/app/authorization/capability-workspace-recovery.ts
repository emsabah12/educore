import type {
    CapabilityRuntime,
} from '@/platform/authorization';
import {
    isOrganizationalContextDeniedFailure,
    type WorkspaceContextRuntime,
} from '@/platform/workspace';

export type CapabilityRecoverySource =
    Pick<
        CapabilityRuntime,
        | 'getState'
        | 'subscribe'
    >;

export type WorkspaceRecoveryTarget =
    Pick<
        WorkspaceContextRuntime,
        | 'getState'
        | 'recoverStaleWorkspace'
    >;

export interface CapabilityWorkspaceRecoveryCoordinatorOptions {
    /*
     * Async recovery errors cannot be surfaced through a
     * React ErrorBoundary.
     *
     * Application composition therefore supplies an
     * explicit reporting boundary instead of allowing an
     * unhandled Promise rejection.
     */
    readonly reportFailure:
        (error: unknown) => void;
}

export interface CapabilityWorkspaceRecoveryCoordinator {
    dispose():
        void;
}

export function createCapabilityWorkspaceRecoveryCoordinator(
    capabilities:
        CapabilityRecoverySource,
    workspace:
        WorkspaceRecoveryTarget,
    options:
        CapabilityWorkspaceRecoveryCoordinatorOptions,
): CapabilityWorkspaceRecoveryCoordinator {
    let disposed =
        false;

    let recoveryInFlight =
        false;

    const synchronize =
        (): void => {
            if (
                disposed
                || recoveryInFlight
            ) {
                return;
            }

            const capabilityState =
                capabilities.getState();

            if (
                capabilityState.status
                    !== 'unavailable'
            ) {
                return;
            }

            const failure =
                capabilityState.failure;

            /*
             * Invalid semantic projections use their own
             * failure kinds. Only canonical response
             * failures can represent the stable backend
             * ORGANIZATIONAL_CONTEXT_DENIED contract.
             */
            if (
                failure.kind
                    !== 'response'
                || ! isOrganizationalContextDeniedFailure(
                    failure,
                )
            ) {
                return;
            }

            const workspaceState =
                workspace.getState();

            /*
             * Workspace runtime deliberately rejects stale
             * recovery unless an organizational Workspace
             * is currently authoritative.
             *
             * Guard here as well so normal TENANT denial
             * cannot become an erroneous recovery attempt.
             */
            if (
                workspaceState.status
                    !== 'ready'
                || workspaceState.current.type
                    === 'TENANT'
            ) {
                return;
            }

            recoveryInFlight =
                true;

            void workspace
                .recoverStaleWorkspace(
                    failure,
                )
                .catch(
                    (
                        error:
                            unknown,
                    ) => {
                        options.reportFailure(
                            error,
                        );
                    },
                )
                .finally(
                    () => {
                        recoveryInFlight =
                            false;
                    },
                );
        };

    const unsubscribe =
        capabilities.subscribe(
            synchronize,
        );

    /*
     * Supporting an already-unavailable source makes this
     * coordinator deterministic regardless of construction
     * timing.
     *
     * In production it will normally be attached before any
     * Capability projection occurs.
     */
    synchronize();

    return {
        dispose() {
            if (
                disposed
            ) {
                return;
            }

            disposed =
                true;

            unsubscribe();
        },
    };
}
