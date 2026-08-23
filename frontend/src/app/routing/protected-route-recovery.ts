import type {
    BrowserAuthRuntime,
    BrowserAuthState,
} from '@/platform/auth';
import type {
    CapabilityRuntime,
} from '@/platform/authorization';
import type {
    MembershipContextRuntime,
} from '@/platform/membership';
import type {
    ProtectedRouteUnavailableSource,
} from '@/platform/routing';
import type {
    WorkspaceContextRuntime,
} from '@/platform/workspace';

export interface ProtectedRouteUnavailableRecoveryDependencies {
    readonly authenticationStatus:
        BrowserAuthState['status'];

    readonly authentication:
        Pick<
            BrowserAuthRuntime,
            'bootstrap'
        >;

    readonly membership:
        Pick<
            MembershipContextRuntime,
            'bootstrap'
        >;

    readonly workspace:
        Pick<
            WorkspaceContextRuntime,
            'bootstrap'
        >;

    readonly capabilities:
        Pick<
            CapabilityRuntime,
            'refresh'
        >;

    readonly reportFailure:
        (
            error:
                unknown,
        ) => void;
}

export async function recoverProtectedRouteUnavailableSource(
    source:
        ProtectedRouteUnavailableSource,
    dependencies:
        ProtectedRouteUnavailableRecoveryDependencies,
): Promise<void> {
    try {
        switch (
            source
        ) {
            case 'authentication':
                await dependencies
                    .authentication
                    .bootstrap();

                return;

            case 'membership':
                await dependencies
                    .membership
                    .bootstrap({
                        /*
                         * Only the initial BrowserSession
                         * Membership-resolution lifecycle
                         * may consume the advisory
                         * restoration hint.
                         *
                         * A retry after already-established
                         * authenticated authority must be a
                         * fresh discovery instead.
                         */
                        restoreHint:
                            dependencies
                                .authenticationStatus
                                === 'membership-context-required',
                    });

                return;

            case 'workspace':
                await dependencies
                    .workspace
                    .bootstrap();

                return;

            case 'authorization':
                await dependencies
                    .capabilities
                    .refresh();

                return;
        }
    } catch (
        error:
            unknown
    ) {
        /*
         * Runtime transport/domain failures resolve into
         * canonical runtime state.
         *
         * Only exceptional invariant/programming failures
         * should reach this catch boundary. React error
         * boundaries cannot catch asynchronous event-handler
         * rejections, so explicitly report them here.
         */
        dependencies.reportFailure(
            error,
        );
    }
}
