import {
    afterEach,
    describe,
    expect,
    it,
    vi,
} from 'vitest';

import type {
    ObservabilityPort,
} from '@/platform/observability/port';
import {
    createBrowserRuntimeObservabilityCoordinator,
} from '@/platform/observability/runtime';

function invokeListener(
    listener:
        EventListenerOrEventListenerObject,
    event:
        Event,
): void {
    if (
        typeof listener
            === 'function'
    ) {
        listener(
            event,
        );

        return;
    }

    listener.handleEvent(
        event,
    );
}

afterEach(
    () => {
        vi.restoreAllMocks();
    },
);

describe(
    'Browser runtime observability coordinator',
    () => {
        it('captures global runtime failures exactly once and owns idempotent listener disposal', () => {
            const observability:
                ObservabilityPort = {
                    captureEvent:
                        vi.fn(),

                    captureException:
                        vi.fn(),
                };

            const addEventListener =
                vi.spyOn(
                    window,
                    'addEventListener',
                )
                    .mockImplementation(
                        () => undefined,
                    );

            const removeEventListener =
                vi.spyOn(
                    window,
                    'removeEventListener',
                )
                    .mockImplementation(
                        () => undefined,
                    );

            const coordinator =
                createBrowserRuntimeObservabilityCoordinator(
                    window,
                    observability,
                    {
                        module:
                            'application',
                    },
                );

            const errorRegistrations =
                addEventListener
                    .mock
                    .calls
                    .filter(
                        (
                            [
                                type,
                            ],
                        ) =>
                            type
                                === 'error',
                    );

            const rejectionRegistrations =
                addEventListener
                    .mock
                    .calls
                    .filter(
                        (
                            [
                                type,
                            ],
                        ) =>
                            type
                                === 'unhandledrejection',
                    );

            expect(
                errorRegistrations,
            ).toHaveLength(
                1,
            );

            expect(
                rejectionRegistrations,
            ).toHaveLength(
                1,
            );

            const errorRegistration =
                errorRegistrations[0];

            const rejectionRegistration =
                rejectionRegistrations[0];

            if (
                errorRegistration
                    === undefined
                || rejectionRegistration
                    === undefined
            ) {
                throw new Error(
                    'Expected browser observability listener registration.',
                );
            }

            const errorListener =
                errorRegistration[1];

            const rejectionListener =
                rejectionRegistration[1];

            const runtimeFailure =
                Object.assign(
                    new Error(
                        'Sensitive runtime detail',
                    ),
                    {
                        authorization:
                            'Bearer runtime-secret',

                        cookie:
                            'browser_session=runtime-secret',
                    },
                );

            const runtimeErrorEvent =
                new ErrorEvent(
                    'error',
                    {
                        cancelable:
                            true,

                        error:
                            runtimeFailure,

                        message:
                            'Sensitive browser error message',

                        filename:
                            'https://example.test/private-path.js',
                    },
                );

            invokeListener(
                errorListener,
                runtimeErrorEvent,
            );

            expect(
                runtimeErrorEvent
                    .defaultPrevented,
            ).toBe(
                false,
            );

            expect(
                observability
                    .captureException,
            ).toHaveBeenNthCalledWith(
                1,
                'browser_runtime_error',
                runtimeFailure,
                {
                    module:
                        'application',
                },
            );

            const rejectionFailure =
                Object.assign(
                    new Error(
                        'Sensitive rejected promise',
                    ),
                    {
                        token:
                            'rejection-secret',
                    },
                );

            const rejectionEvent =
                new Event(
                    'unhandledrejection',
                    {
                        cancelable:
                            true,
                    },
                );

            Object.defineProperty(
                rejectionEvent,
                'reason',
                {
                    configurable:
                        true,

                    value:
                        rejectionFailure,
                },
            );

            invokeListener(
                rejectionListener,
                rejectionEvent,
            );

            expect(
                rejectionEvent
                    .defaultPrevented,
            ).toBe(
                false,
            );

            expect(
                observability
                    .captureException,
            ).toHaveBeenNthCalledWith(
                2,
                'browser_unhandled_rejection',
                rejectionFailure,
                {
                    module:
                        'application',
                },
            );

            expect(
                observability
                    .captureException,
            ).toHaveBeenCalledTimes(
                2,
            );

            expect(
                observability
                    .captureEvent,
            ).not.toHaveBeenCalled();

            coordinator.dispose();
            coordinator.dispose();

            const errorRemovals =
                removeEventListener
                    .mock
                    .calls
                    .filter(
                        (
                            [
                                type,
                            ],
                        ) =>
                            type
                                === 'error',
                    );

            const rejectionRemovals =
                removeEventListener
                    .mock
                    .calls
                    .filter(
                        (
                            [
                                type,
                            ],
                        ) =>
                            type
                                === 'unhandledrejection',
                    );

            expect(
                errorRemovals,
            ).toHaveLength(
                1,
            );

            expect(
                rejectionRemovals,
            ).toHaveLength(
                1,
            );

            expect(
                errorRemovals[0]?.[1],
            ).toBe(
                errorListener,
            );

            expect(
                rejectionRemovals[0]?.[1],
            ).toBe(
                rejectionListener,
            );
        });
    },
);
