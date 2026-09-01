import type {
    BrowserApiFailure,
    BrowserApiResponseFailure,
} from '@/platform/api';

import type {
    AuthenticatedBootstrapData,
    BrowserLoginData,
} from '@/platform/auth/contract';

export interface UnknownBrowserAuthState {
    readonly status:
        'unknown';
}

export interface AnonymousBrowserAuthState {
    readonly status:
        'anonymous';

    readonly failure:
        BrowserApiFailure | null;
}

export interface AuthenticatingBrowserAuthState {
    readonly status:
        'authenticating';
}

/*
 * Global identity authentication is intentionally
 * distinct from Membership/Tenant readiness.
 *
 * Fresh Browser login terminates in this state.
 */
export interface IdentityAuthenticatedBrowserAuthState {
    readonly status:
        'identity-authenticated';

    readonly identity:
        BrowserLoginData;
}

export interface MembershipContextRequiredBrowserAuthState {
    readonly status:
        'membership-context-required';

    readonly failure:
        BrowserApiResponseFailure;
}

export interface AuthenticatedBrowserAuthState {
    readonly status:
        'authenticated';

    readonly identity:
        AuthenticatedBootstrapData;
}

export type BrowserAuthenticatedIdentity =
    | BrowserLoginData
    | AuthenticatedBootstrapData;

export interface LoggingOutBrowserAuthState {
    readonly status:
        'logging-out';

    readonly identity:
        BrowserAuthenticatedIdentity;
}

export interface UnavailableBrowserAuthState {
    readonly status:
        'unavailable';

    readonly failure:
        BrowserApiFailure;
}

export type BrowserAuthState =
    | UnknownBrowserAuthState
    | AnonymousBrowserAuthState
    | AuthenticatingBrowserAuthState
    | IdentityAuthenticatedBrowserAuthState
    | MembershipContextRequiredBrowserAuthState
    | AuthenticatedBrowserAuthState
    | LoggingOutBrowserAuthState
    | UnavailableBrowserAuthState;

export type BrowserAuthAction =
    | {
        readonly type:
            'BECAME_ANONYMOUS';

        readonly failure:
            BrowserApiFailure | null;
    }
    | {
        readonly type:
            'LOGIN_STARTED';
    }
    | {
        readonly type:
            'LOGIN_ACCEPTED';

        readonly identity:
            BrowserLoginData;
    }
    | {
        readonly type:
            'CONTEXT_REQUIRED';

        readonly failure:
            BrowserApiResponseFailure;
    }
    | {
        readonly type:
            'BECAME_AUTHENTICATED';

        readonly identity:
            AuthenticatedBootstrapData;
    }
    | {
        readonly type:
            'BECAME_UNAVAILABLE';

        readonly failure:
            BrowserApiFailure;
    }
    | {
        readonly type:
            'LOGOUT_STARTED';
    }
    | {
        readonly type:
            'LOGOUT_COMPLETED';
    }
    | {
        readonly type:
            'SESSION_EXPIRED';

        readonly failure:
            BrowserApiResponseFailure;
    };

export function createInitialBrowserAuthState():
    UnknownBrowserAuthState {
    return {
        status:
            'unknown',
    };
}

function assertTransition(
    state:
        BrowserAuthState,

    action:
        BrowserAuthAction,

    allowedStatuses:
        readonly BrowserAuthState['status'][],
): void {
    if (
        allowedStatuses.includes(
            state.status,
        )
    ) {
        return;
    }

    throw new Error(
        [
            'Invalid EduCore BrowserAuth transition:',
            state.status,
            '->',
            action.type,
        ].join(' '),
    );
}

export function browserAuthReducer(
    state:
        BrowserAuthState,

    action:
        BrowserAuthAction,
): BrowserAuthState {
    switch (action.type) {
        case 'BECAME_ANONYMOUS':
            return {
                status:
                    'anonymous',

                failure:
                    action.failure,
            };

        case 'LOGIN_STARTED':
            assertTransition(
                state,
                action,
                [
                    'anonymous',
                ],
            );

            return {
                status:
                    'authenticating',
            };

        case 'LOGIN_ACCEPTED':
            assertTransition(
                state,
                action,
                [
                    'authenticating',
                ],
            );

            return {
                status:
                    'identity-authenticated',

                identity:
                    action.identity,
            };

        case 'CONTEXT_REQUIRED':
            assertTransition(
                state,
                action,
                [
                    'unknown',
                    'identity-authenticated',
                    'authenticated',
                    'unavailable',
                ],
            );

            return {
                status:
                    'membership-context-required',

                failure:
                    action.failure,
            };

        case 'BECAME_AUTHENTICATED':
            assertTransition(
                state,
                action,
                [
                    'unknown',
                    'identity-authenticated',
                    'membership-context-required',
                    'authenticated',
                    'unavailable',
                ],
            );

            return {
                status:
                    'authenticated',

                identity:
                    action.identity,
            };

        case 'BECAME_UNAVAILABLE':
            return {
                status:
                    'unavailable',

                failure:
                    action.failure,
            };

        case 'LOGOUT_STARTED':
            /*
             * Keep the runtime fail-closed transition guard
             * explicit here so TypeScript can narrow the
             * discriminated BrowserAuthState union before
             * identity is accessed.
             */
            if (
                state.status
                    !== 'identity-authenticated'
                && state.status
                    !== 'authenticated'
            ) {
                throw new Error(
                    [
                        'Invalid EduCore BrowserAuth transition:',
                        state.status,
                        '->',
                        action.type,
                    ].join(' '),
                );
            }

            return {
                status:
                    'logging-out',

                identity:
                    state.identity,
            };

        case 'LOGOUT_COMPLETED':
            assertTransition(
                state,
                action,
                [
                    'logging-out',
                ],
            );

            return {
                status:
                    'anonymous',

                failure:
                    null,
            };

        case 'SESSION_EXPIRED':
            assertTransition(
                state,
                action,
                [
                    'identity-authenticated',
                    'authenticated',
                    'logging-out',
                    'membership-context-required',
                ],
            );

            return {
                status:
                    'anonymous',

                failure:
                    action.failure,
            };
    }
}
