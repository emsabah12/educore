import {
    act,
    render,
    screen,
} from '@testing-library/react';
import {
    describe,
    expect,
    it,
} from 'vitest';

import {
    CapabilityContextProvider,
    useCapabilityRuntime,
    useCapabilityState,
} from '@/app/authorization/CapabilityContextProvider';
import type {
    CapabilityRuntime,
    CapabilityState,
} from '@/platform/authorization';

interface ControlledCapabilityRuntime {
    readonly runtime:
        CapabilityRuntime;

    readonly calls: {
        bootstrap:
            number;

        refresh:
            number;

        reset:
            number;
    };

    publish(
        state:
            CapabilityState,
    ): void;
}

function createControlledCapabilityRuntime(
    initialState:
        CapabilityState = {
            status:
                'unresolved',
        },
): ControlledCapabilityRuntime {
    let state =
        initialState;

    const listeners =
        new Set<
            () => void
        >();

    const calls = {
        bootstrap:
            0,

        refresh:
            0,

        reset:
            0,
    };

    const notify =
        () => {
            for (
                const listener
                of listeners
            ) {
                listener();
            }
        };

    const runtime:
        CapabilityRuntime = {
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

        async bootstrap() {
            calls.bootstrap +=
                1;

            return state;
        },

        async refresh() {
            calls.refresh +=
                1;

            return state;
        },

        reset() {
            calls.reset +=
                1;

            state = {
                status:
                    'unresolved',
            };

            notify();

            return state;
        },

        dispose() {
            listeners.clear();
        },
    };

    return {
        runtime,
        calls,

        publish(
            nextState,
        ) {
            state =
                nextState;

            notify();
        },
    };
}

function CapabilityStateProbe() {
    const state =
        useCapabilityState();

    return (
        <div>
            <span>
                Capability status
            </span>

            <output
                aria-label="Capability status"
            >
                {state.status}
            </output>
        </div>
    );
}

function CapabilityRuntimeProbe({
    expectedRuntime,
}: {
    readonly expectedRuntime:
        CapabilityRuntime;
}) {
    const runtime =
        useCapabilityRuntime();

    return (
        <output
            aria-label="Capability runtime identity"
        >
            {
                runtime
                    === expectedRuntime
                    ? 'same'
                    : 'different'
            }
        </output>
    );
}

describe(
    'CapabilityContextProvider',
    () => {
        it('exposes the composed Capability runtime without dispatching lifecycle work on mount', () => {
            const controlled =
                createControlledCapabilityRuntime();

            render(
                <CapabilityContextProvider
                    runtime={
                        controlled.runtime
                    }
                >
                    <CapabilityRuntimeProbe
                        expectedRuntime={
                            controlled.runtime
                        }
                    />

                    <CapabilityStateProbe />
                </CapabilityContextProvider>,
            );

            expect(
                screen.getByLabelText(
                    'Capability runtime identity',
                ),
            ).toHaveTextContent(
                'same',
            );

            expect(
                screen.getByLabelText(
                    'Capability status',
                ),
            ).toHaveTextContent(
                'unresolved',
            );

            /*
             * CapabilityRuntime already reacts to upstream
             * Membership/Workspace subscriptions.
             *
             * React must not create an additional lifecycle
             * owner.
             */
            expect(
                controlled.calls,
            ).toEqual({
                bootstrap:
                    0,

                refresh:
                    0,

                reset:
                    0,
            });
        });

        it('publishes Capability state changes through useSyncExternalStore without invoking runtime commands', () => {
            const controlled =
                createControlledCapabilityRuntime();

            render(
                <CapabilityContextProvider
                    runtime={
                        controlled.runtime
                    }
                >
                    <CapabilityStateProbe />
                </CapabilityContextProvider>,
            );

            expect(
                screen.getByLabelText(
                    'Capability status',
                ),
            ).toHaveTextContent(
                'unresolved',
            );

            act(() => {
                controlled.publish({
                    status:
                        'loading',
                });
            });

            expect(
                screen.getByLabelText(
                    'Capability status',
                ),
            ).toHaveTextContent(
                'loading',
            );

            act(() => {
                controlled.publish({
                    status:
                        'unavailable',

                    failure: {
                        ok:
                            false,

                        kind:
                            'network',

                        cause:
                            new TypeError(
                                'Network unavailable',
                            ),
                    },
                });
            });

            expect(
                screen.getByLabelText(
                    'Capability status',
                ),
            ).toHaveTextContent(
                'unavailable',
            );

            expect(
                controlled.calls,
            ).toEqual({
                bootstrap:
                    0,

                refresh:
                    0,

                reset:
                    0,
            });
        });

        it('fails closed when Capability hooks are used outside their Provider', () => {
            function InvalidConsumer() {
                useCapabilityState();

                return null;
            }

            expect(
                () => {
                    render(
                        <InvalidConsumer />,
                    );
                },
            ).toThrow(
                'EduCore Capability hooks require CapabilityContextProvider.',
            );
        });
    },
);
