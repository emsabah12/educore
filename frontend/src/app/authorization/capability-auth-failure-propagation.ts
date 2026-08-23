import type {
    BrowserApiFailure,
} from '@/platform/api';
import type {
    BrowserAuthRuntime,
} from '@/platform/auth';
import type {
    CapabilityRuntime,
    CapabilityStateFailure,
} from '@/platform/authorization';

export type CapabilityAuthFailureSource =
    Pick<
        CapabilityRuntime,
        | 'getState'
        | 'subscribe'
    >;

export type CapabilityAuthFailureTarget =
    Pick<
        BrowserAuthRuntime,
        'observeFailure'
    >;

export interface CapabilityAuthFailurePropagationCoordinator {
    dispose():
        void;
}

function isBrowserApiCapabilityFailure(
    failure:
        CapabilityStateFailure,
): failure is BrowserApiFailure {
    return (
        failure.kind
            !== 'invalid-payload'
        && failure.kind
            !== 'scope-mismatch'
    );
}

export function createCapabilityAuthFailurePropagationCoordinator(
    capabilities:
        CapabilityAuthFailureSource,
    authentication:
        CapabilityAuthFailureTarget,
): CapabilityAuthFailurePropagationCoordinator {
    let disposed =
        false;

    const synchronize =
        (): void => {
            if (
                disposed
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
             * Capability validation failures represent
             * projection/contract authority problems.
             *
             * They are not Browser API failures and must
             * never be allowed to influence authentication
             * truth.
             */
            if (
                ! isBrowserApiCapabilityFailure(
                    failure,
                )
            ) {
                return;
            }

            /*
             * BrowserAuthRuntime remains the sole semantic
             * owner of authentication truth.
             *
             * This coordinator deliberately forwards every
             * Browser API failure instead of duplicating
             * authentication code/status classification.
             */
            authentication.observeFailure(
                failure,
            );
        };

    const unsubscribe =
        capabilities.subscribe(
            synchronize,
        );

    /*
     * Construction timing must not determine behavior.
     * Observe a Capability source that was already
     * unavailable before this coordinator was attached.
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
