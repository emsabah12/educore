import type {
    BrowserApiFailure,
    BrowserApiProtocolFailure,
    BrowserApiResult,
} from '@/platform/api';

import type {
    BrowserAuthBootstrapOptions,
} from '@/platform/auth/bootstrap';
import type {
    AuthenticatedBootstrapSuccess,
    BrowserLoginRequest,
    BrowserLoginSuccess,
    BrowserLogoutSuccess,
} from '@/platform/auth/contract';
import {
    isBrowserMembershipContextRequiredFailure,
    isBrowserSessionAuthenticationRequiredFailure,
} from '@/platform/auth/failure';
import type {
    BrowserLogoutOptions,
} from '@/platform/auth/logout';
import type {
    BrowserAuthOperations,
} from '@/platform/auth/operations';
import { createBrowserAuthAbortOptions } from '@/platform/auth/request-options';
import type {
    BrowserLoginOptions,
} from '@/platform/auth/service';
import {
    browserAuthReducer,
    createInitialBrowserAuthState,
    type BrowserAuthAction,
    type BrowserAuthState,
} from '@/platform/auth/state';

export type BrowserAuthStateListener = (
    state: BrowserAuthState,
) => void;

export interface BrowserAuthRuntime {
    getState(): BrowserAuthState;

    subscribe(
        listener: BrowserAuthStateListener,
    ): () => void;

    bootstrap(
        options?: BrowserAuthBootstrapOptions,
    ): Promise<BrowserAuthState>;

    login(
        request: BrowserLoginRequest,
        options?: BrowserLoginOptions,
    ): Promise<BrowserAuthState>;

    logout(
        options?: BrowserLogoutOptions,
    ): Promise<BrowserAuthState>;

    observeFailure(
        failure: BrowserApiFailure,
    ): BrowserAuthState;
}

function createMissingSuccessBodyFailure(
    status: number,
): BrowserApiProtocolFailure {
    return {
        ok: false,
        kind: 'protocol',
        status,
        message:
            'EduCore API returned an unexpected error response.',
    };
}

export function createBrowserAuthRuntime(
    operations: BrowserAuthOperations,
): BrowserAuthRuntime {
    let state: BrowserAuthState =
        createInitialBrowserAuthState();

    const listeners =
        new Set<
            BrowserAuthStateListener
        >();

    function publish(): void {
        for (
            const listener
            of listeners
        ) {
            listener(
                state,
            );
        }
    }

    function dispatch(
        action: BrowserAuthAction,
    ): BrowserAuthState {
        state = browserAuthReducer(
            state,
            action,
        );

        publish();

        return state;
    }

    function applyAuthenticationFailure(
        failure: BrowserApiFailure,
    ): boolean {
        if (
            isBrowserSessionAuthenticationRequiredFailure(
                failure,
            )
        ) {
            if (
                state.status
                    === 'authenticated'
                || state.status
                    === 'logging-out'
                || state.status
                    === 'membership-context-required'
            ) {
                dispatch({
                    type:
                        'SESSION_EXPIRED',
                    failure,
                });
            } else {
                dispatch({
                    type:
                        'BECAME_ANONYMOUS',
                    failure,
                });
            }

            return true;
        }

        if (
            isBrowserMembershipContextRequiredFailure(
                failure,
            )
        ) {
            if (
                state.status
                    === 'membership-context-required'
            ) {
                return true;
            }

            if (
                state.status
                    === 'unknown'
                || state.status
                    === 'resolving-context'
                || state.status
                    === 'authenticated'
                || state.status
                    === 'unavailable'
            ) {
                dispatch({
                    type:
                        'CONTEXT_REQUIRED',
                    failure,
                });
            }

            return true;
        }

        return false;
    }

    function applyBootstrapResult(
        result: BrowserApiResult<
            AuthenticatedBootstrapSuccess
        >,
    ): BrowserAuthState {
        if (! result.ok) {
            /*
             * Cancellation is a caller lifecycle decision,
             * not authentication truth.
             *
             * This is particularly important for React
             * StrictMode's development-only Effect
             * setup/cleanup probe.
             */
            if (
                result.kind
                    === 'aborted'
            ) {
                return state;
            }

            if (
                applyAuthenticationFailure(
                    result,
                )
            ) {
                return state;
            }

            return dispatch({
                type:
                    'BECAME_UNAVAILABLE',
                failure:
                    result,
            });
        }

        if (
            result.data === undefined
        ) {
            return dispatch({
                type:
                    'BECAME_UNAVAILABLE',
                failure:
                    createMissingSuccessBodyFailure(
                        result.status,
                    ),
            });
        }

        return dispatch({
            type:
                'BECAME_AUTHENTICATED',
            identity:
                result.data.data,
        });
    }

    return {
        getState() {
            return state;
        },

        subscribe(
            listener,
        ) {
            listeners.add(
                listener,
            );

            return () => {
                listeners.delete(
                    listener,
                );
            };
        },

        async bootstrap(
            options,
        ) {
            const result =
                await operations.bootstrap(
                    options,
                );

            return applyBootstrapResult(
                result,
            );
        },

        async login(
            request,
            options,
        ) {
            dispatch({
                type:
                    'LOGIN_STARTED',
            });

            const loginResult:
                BrowserApiResult<
                    BrowserLoginSuccess
                > =
                await operations.login(
                    request,
                    options,
                );

            if (! loginResult.ok) {
                return dispatch({
                    type:
                        'BECAME_ANONYMOUS',
                    failure:
                        loginResult,
                });
            }

            if (
                loginResult.data
                    === undefined
            ) {
                return dispatch({
                    type:
                        'BECAME_UNAVAILABLE',
                    failure:
                        createMissingSuccessBodyFailure(
                            loginResult.status,
                        ),
                });
            }

            const login =
                loginResult.data.data;

            dispatch({
                type:
                    'LOGIN_ACCEPTED',
                login,
            });

            const bootstrapResult =
                await operations.bootstrap({
                    membershipId:
                        login.membership_id,

                    ...createBrowserAuthAbortOptions(
                        options?.signal,
                    ),
                });

            return applyBootstrapResult(
                bootstrapResult,
            );
        },

        async logout(
            options,
        ) {
            dispatch({
                type:
                    'LOGOUT_STARTED',
            });

            const result:
                BrowserApiResult<
                    BrowserLogoutSuccess
                > =
                await operations.logout(
                    options,
                );

            if (! result.ok) {
                return dispatch({
                    type:
                        'BECAME_UNAVAILABLE',
                    failure:
                        result,
                });
            }

            if (
                result.data === undefined
            ) {
                return dispatch({
                    type:
                        'BECAME_UNAVAILABLE',
                    failure:
                        createMissingSuccessBodyFailure(
                            result.status,
                        ),
                });
            }

            return dispatch({
                type:
                    'LOGOUT_COMPLETED',
            });
        },

        observeFailure(
            failure,
        ) {
            applyAuthenticationFailure(
                failure,
            );

            return state;
        },
    };
}
