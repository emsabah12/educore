import type {
    BrowserAuthState,
} from '@/platform/auth';
import type {
    CanonicalMembershipContext,
    MembershipContextState,
} from '@/platform/membership';

type NonReadyMembershipStatus =
    Exclude<
        MembershipContextState['status'],
        'ready'
    >;

export type WorkspaceMembershipLifecycleObservation =
    | {
        readonly status:
            'ready';

        readonly context:
            CanonicalMembershipContext;
    }
    | {
        readonly status:
            NonReadyMembershipStatus;
    };

export interface WorkspaceBootstrapLifecycleDecision {
    readonly contextIdentity:
        string;

    readonly restoreHint:
        boolean;
}

export interface WorkspaceBootstrapLifecycleClassifier {
    observe(
        authenticationStatus:
            BrowserAuthState['status'],
        membership:
            WorkspaceMembershipLifecycleObservation,
    ):
        | WorkspaceBootstrapLifecycleDecision
        | null;
}

function createContextIdentity(
    context:
        CanonicalMembershipContext,
): string {
    return JSON.stringify([
        context.membership.id,
        context.tenant.id,
    ]);
}

function isFreshAuthenticationTransition(
    status:
        BrowserAuthState['status'],
): boolean {
    return (
        status
            === 'authenticating'
        || status
            === 'resolving-context'
    );
}

export function createWorkspaceBootstrapLifecycleClassifier():
    WorkspaceBootstrapLifecycleClassifier {
    let freshContextPending =
        false;

    let currentDecision:
        WorkspaceBootstrapLifecycleDecision
        | null =
            null;

    /*
     * The classifier observes explicit upstream lifecycle
     * states rather than guessing from "first render".
     *
     * The same decision is retained for the same canonical
     * Membership/Tenant context so React StrictMode effect
     * replay cannot change restoration policy midway
     * through one bootstrap.
     */
    return {
        observe(
            authenticationStatus,
            membership,
        ) {
            if (
                isFreshAuthenticationTransition(
                    authenticationStatus,
                )
            ) {
                freshContextPending =
                    true;

                currentDecision =
                    null;
            }

            if (
                membership.status
                    === 'switching'
            ) {
                /*
                 * Membership switching means the next
                 * canonical context is fresh even if the
                 * target eventually resolves to identifiers
                 * seen earlier in this browser tab.
                 */
                freshContextPending =
                    true;

                currentDecision =
                    null;
            }

            if (
                membership.status
                    !== 'ready'
            ) {
                return null;
            }

            const contextIdentity =
                createContextIdentity(
                    membership.context,
                );

            if (
                currentDecision
                    ?.contextIdentity
                    === contextIdentity
            ) {
                return currentDecision;
            }

            currentDecision = {
                contextIdentity,

                restoreHint:
                    ! freshContextPending,
            };

            freshContextPending =
                false;

            return currentDecision;
        },
    };
}

export function createWorkspaceMembershipLifecycleObservation(
    state:
        MembershipContextState,
): WorkspaceMembershipLifecycleObservation {
    if (
        state.status
            === 'ready'
    ) {
        return {
            status:
                'ready',

            context:
                state.context,
        };
    }

    return {
        status:
            state.status,
    };
}
