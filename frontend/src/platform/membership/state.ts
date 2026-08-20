import type {
    BrowserApiFailure,
} from '@/platform/api';

import type {
    CanonicalMembershipContext,
    MembershipSummary,
} from '@/platform/membership/contract';

export interface UnresolvedMembershipContextState {
    readonly status:
        'unresolved';
}

export interface DiscoveringMembershipContextState {
    readonly status:
        'discovering';

    readonly context:
        CanonicalMembershipContext | null;
}

export interface ReadyMembershipContextState {
    readonly status:
        'ready';

    readonly memberships:
        readonly MembershipSummary[];

    readonly context:
        CanonicalMembershipContext;

    readonly failure:
        BrowserApiFailure | null;
}

export interface SelectionRequiredMembershipContextState {
    readonly status:
        'selection-required';

    readonly memberships:
        readonly MembershipSummary[];

    readonly failure:
        BrowserApiFailure | null;
}

export interface EmptyMembershipContextState {
    readonly status:
        'empty';
}

export interface SwitchingMembershipContextState {
    readonly status:
        'switching';

    readonly memberships:
        readonly MembershipSummary[];

    readonly context:
        CanonicalMembershipContext | null;

    readonly target:
        MembershipSummary;
}

export interface UnavailableMembershipContextState {
    readonly status:
        'unavailable';

    readonly context:
        CanonicalMembershipContext | null;

    readonly failure:
        BrowserApiFailure;
}

export type MembershipContextState =
    | UnresolvedMembershipContextState
    | DiscoveringMembershipContextState
    | ReadyMembershipContextState
    | SelectionRequiredMembershipContextState
    | EmptyMembershipContextState
    | SwitchingMembershipContextState
    | UnavailableMembershipContextState;

export type MembershipContextAction =
    | {
        readonly type:
            'DISCOVERY_STARTED';

        readonly context:
            CanonicalMembershipContext | null;
    }
    | {
        readonly type:
            'DISCOVERY_READY';

        readonly memberships:
            readonly MembershipSummary[];
    }
    | {
        readonly type:
            'DISCOVERY_EMPTY';
    }
    | {
        readonly type:
            'DISCOVERY_UNAVAILABLE';

        readonly failure:
            BrowserApiFailure;
    }
    | {
        readonly type:
            'SWITCH_STARTED';

        readonly membershipId:
            MembershipSummary['membership_id'];
    }
    | {
        readonly type:
            'CONTEXT_CONFIRMED';

        readonly context:
            CanonicalMembershipContext;
    }
    | {
        readonly type:
            'SWITCH_FAILED';

        readonly failure:
            BrowserApiFailure;
    }
    | {
        readonly type:
            'RESET';
    };

export function createInitialMembershipContextState():
    UnresolvedMembershipContextState {
    return {
        status:
            'unresolved',
    };
}

function invalidTransition(
    state: MembershipContextState,
    action: MembershipContextAction,
): never {
    throw new Error(
        [
            'Invalid EduCore MembershipContext transition:',
            state.status,
            '->',
            action.type,
        ].join(' '),
    );
}

function findMembership(
    memberships:
        readonly MembershipSummary[],
    membershipId:
        MembershipSummary['membership_id'],
): MembershipSummary | undefined {
    return memberships.find(
        (membership) =>
            membership.membership_id
                === membershipId,
    );
}

function contextMatchesMembership(
    context:
        CanonicalMembershipContext,
    membership:
        MembershipSummary,
): boolean {
    return (
        context.membership.id
            === membership.membership_id
        && context.tenant.id
            === membership.tenant_id
    );
}

function assertNonEmptyMemberships(
    memberships:
        readonly MembershipSummary[],
): void {
    if (memberships.length === 0) {
        throw new Error(
            'EduCore MembershipContext ready state requires at least one Membership.',
        );
    }
}

export function membershipContextReducer(
    state: MembershipContextState,
    action: MembershipContextAction,
): MembershipContextState {
    switch (action.type) {
        case 'DISCOVERY_STARTED':
            /*
             * Discovery may restart from any stable state or
             * replace another in-flight discovery.
             *
             * Runtime operation revisions ensure stale
             * asynchronous results cannot overwrite the
             * latest discovery.
             *
             * A Membership switch is the only state that
             * cannot be interrupted by discovery.
             */
            if (
                state.status
                    === 'switching'
            ) {
                return invalidTransition(
                    state,
                    action,
                );
            }

            return {
                status:
                    'discovering',
                context:
                    action.context,
            };

        case 'DISCOVERY_READY': {
            if (
                state.status
                    !== 'discovering'
            ) {
                return invalidTransition(
                    state,
                    action,
                );
            }

            assertNonEmptyMemberships(
                action.memberships,
            );

            if (
                state.context
                    === null
            ) {
                return {
                    status:
                        'selection-required',
                    memberships:
                        action.memberships,
                    failure:
                        null,
                };
            }

            const currentMembership =
                findMembership(
                    action.memberships,
                    state.context
                        .membership.id,
                );

            if (
                currentMembership
                    === undefined
                || ! contextMatchesMembership(
                    state.context,
                    currentMembership,
                )
            ) {
                throw new Error(
                    'EduCore MembershipContext discovery does not contain the canonical Membership/Tenant context.',
                );
            }

            return {
                status:
                    'ready',
                memberships:
                    action.memberships,
                context:
                    state.context,
                failure:
                    null,
            };
        }

        case 'DISCOVERY_EMPTY':
            if (
                state.status
                    !== 'discovering'
            ) {
                return invalidTransition(
                    state,
                    action,
                );
            }

            if (
                state.context
                    !== null
            ) {
                throw new Error(
                    'EduCore MembershipContext cannot become empty while canonical Membership/Tenant context exists.',
                );
            }

            return {
                status:
                    'empty',
            };

        case 'DISCOVERY_UNAVAILABLE':
            if (
                state.status
                    !== 'discovering'
            ) {
                return invalidTransition(
                    state,
                    action,
                );
            }

            return {
                status:
                    'unavailable',
                context:
                    state.context,
                failure:
                    action.failure,
            };

        case 'SWITCH_STARTED': {
            if (
                state.status
                    !== 'ready'
                && state.status
                    !== 'selection-required'
            ) {
                return invalidTransition(
                    state,
                    action,
                );
            }

            const target =
                findMembership(
                    state.memberships,
                    action.membershipId,
                );

            if (
                target
                    === undefined
            ) {
                throw new Error(
                    'EduCore MembershipContext switch target is not available to the authenticated Person.',
                );
            }

            return {
                status:
                    'switching',
                memberships:
                    state.memberships,
                context:
                    state.status
                        === 'ready'
                        ? state.context
                        : null,
                target,
            };
        }

        case 'CONTEXT_CONFIRMED':
            if (
                state.status
                    !== 'switching'
            ) {
                return invalidTransition(
                    state,
                    action,
                );
            }

            if (
                ! contextMatchesMembership(
                    action.context,
                    state.target,
                )
            ) {
                throw new Error(
                    'EduCore MembershipContext canonical confirmation does not match the requested switch target.',
                );
            }

            return {
                status:
                    'ready',
                memberships:
                    state.memberships,
                context:
                    action.context,
                failure:
                    null,
            };

        case 'SWITCH_FAILED':
            if (
                state.status
                    !== 'switching'
            ) {
                return invalidTransition(
                    state,
                    action,
                );
            }

            if (
                state.context
                    === null
            ) {
                return {
                    status:
                        'selection-required',
                    memberships:
                        state.memberships,
                    failure:
                        action.failure,
                };
            }

            return {
                status:
                    'ready',
                memberships:
                    state.memberships,
                context:
                    state.context,
                failure:
                    action.failure,
            };

        case 'RESET':
            return createInitialMembershipContextState();
    }
}
