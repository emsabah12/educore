import {
    describe,
    expect,
    it,
    vi,
} from 'vitest';

import type {
    BrowserApiFailure,
} from '@/platform/api';
import type {
    CapabilityState,
} from '@/platform/authorization';

import {
    createCapabilityAuthFailurePropagationCoordinator,
} from '@/app/authorization/capability-auth-failure-propagation';

const authenticationContextDeniedFailure:
    BrowserApiFailure = {
        ok: false,
        kind:
            'response',
        status:
            403,
        error: {
            status:
                'error',
            code:
                'AUTHENTICATION_CONTEXT_DENIED',
            message:
                'Authentication context missing or invalid.',
        },
    };

const sessionRequiredFailure:
    BrowserApiFailure = {
        ok: false,
        kind:
            'response',
        status:
            401,
        error: {
            status:
                'error',
            code:
                'BROWSER_SESSION_AUTHENTICATION_REQUIRED',
            message:
                'Authenticated browser session is required.',
        },
    };

const networkFailure:
    BrowserApiFailure = {
        ok: false,
        kind:
            'network',
        cause:
            new TypeError(
                'Network unavailable.',
            ),
    };

function createCapabilitySource(
    initialState:
        CapabilityState = {
            status:
                'unresolved',
        },
) {
    let state =
        initialState;

    const listeners =
        new Set<
            () => void
        >();

    return {
        getState() {
            return state;
        },

        subscribe(
            listener:
                () => void,
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

        publish(
            nextState:
                CapabilityState,
        ) {
            state =
                nextState;

            for (
                const listener
                of listeners
            ) {
                listener();
            }
        },
    };
}

describe(
    'Capability authentication failure propagation',
    () => {
        it('forwards canonical authentication context denial to BrowserAuth observation', () => {
            const capabilities =
                createCapabilitySource();

            const authentication = {
                observeFailure:
                    vi.fn(),
            };

            const coordinator =
                createCapabilityAuthFailurePropagationCoordinator(
                    capabilities,
                    authentication,
                );

            capabilities.publish({
                status:
                    'unavailable',
                failure:
                    authenticationContextDeniedFailure,
            });

            expect(
                authentication
                    .observeFailure,
            ).toHaveBeenCalledTimes(
                1,
            );

            expect(
                authentication
                    .observeFailure,
            ).toHaveBeenCalledWith(
                authenticationContextDeniedFailure,
            );

            coordinator.dispose();
        });

        it('forwards BrowserSession authentication loss without reclassifying it locally', () => {
            const capabilities =
                createCapabilitySource();

            const authentication = {
                observeFailure:
                    vi.fn(),
            };

            const coordinator =
                createCapabilityAuthFailurePropagationCoordinator(
                    capabilities,
                    authentication,
                );

            capabilities.publish({
                status:
                    'unavailable',
                failure:
                    sessionRequiredFailure,
            });

            expect(
                authentication
                    .observeFailure,
            ).toHaveBeenCalledWith(
                sessionRequiredFailure,
            );

            coordinator.dispose();
        });

        it('forwards unrelated Browser API failures so BrowserAuth remains the semantic owner', () => {
            const capabilities =
                createCapabilitySource();

            const authentication = {
                observeFailure:
                    vi.fn(),
            };

            const coordinator =
                createCapabilityAuthFailurePropagationCoordinator(
                    capabilities,
                    authentication,
                );

            capabilities.publish({
                status:
                    'unavailable',
                failure:
                    networkFailure,
            });

            expect(
                authentication
                    .observeFailure,
            ).toHaveBeenCalledWith(
                networkFailure,
            );

            coordinator.dispose();
        });

        it('does not forward capability projection validation failures as authentication failures', () => {
            const capabilities =
                createCapabilitySource();

            const authentication = {
                observeFailure:
                    vi.fn(),
            };

            const coordinator =
                createCapabilityAuthFailurePropagationCoordinator(
                    capabilities,
                    authentication,
                );

            capabilities.publish({
                status:
                    'unavailable',
                failure: {
                    ok:
                        false,
                    kind:
                        'scope-mismatch',
                },
            });

            expect(
                authentication
                    .observeFailure,
            ).not.toHaveBeenCalled();

            coordinator.dispose();
        });

        it('observes an already unavailable Browser API failure at coordinator construction', () => {
            const capabilities =
                createCapabilitySource({
                    status:
                        'unavailable',
                    failure:
                        authenticationContextDeniedFailure,
                });

            const authentication = {
                observeFailure:
                    vi.fn(),
            };

            const coordinator =
                createCapabilityAuthFailurePropagationCoordinator(
                    capabilities,
                    authentication,
                );

            expect(
                authentication
                    .observeFailure,
            ).toHaveBeenCalledTimes(
                1,
            );

            expect(
                authentication
                    .observeFailure,
            ).toHaveBeenCalledWith(
                authenticationContextDeniedFailure,
            );

            coordinator.dispose();
        });

        it('stops propagating failures after disposal', () => {
            const capabilities =
                createCapabilitySource();

            const authentication = {
                observeFailure:
                    vi.fn(),
            };

            const coordinator =
                createCapabilityAuthFailurePropagationCoordinator(
                    capabilities,
                    authentication,
                );

            coordinator.dispose();

            capabilities.publish({
                status:
                    'unavailable',
                failure:
                    authenticationContextDeniedFailure,
            });

            expect(
                authentication
                    .observeFailure,
            ).not.toHaveBeenCalled();
        });
    },
);
