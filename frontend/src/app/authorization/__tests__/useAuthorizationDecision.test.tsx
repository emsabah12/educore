import {
    type PropsWithChildren,
} from 'react';
import {
    act,
    renderHook,
} from '@testing-library/react';
import {
    describe,
    expect,
    it,
} from 'vitest';

import {
    CapabilityContextProvider,
} from '@/app/authorization/CapabilityContextProvider';
import {
    useAuthorizationDecision,
    useAuthorizationDecisionEvaluator,
} from '@/app/authorization/useAuthorizationDecision';
import type {
    CapabilityRuntime,
    CapabilityState,
    PermissionRequirement,
} from '@/platform/authorization';

const studentsView =
    'academic.students.view';

const gradesWrite =
    'academic.grades.write';

const studentsRequirement:
    PermissionRequirement = {
        mode:
            'single',

        permission:
            studentsView,
    };

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

function readyState(
    permissions:
        string[],
    isGlobalSuperadmin =
        false,
): CapabilityState {
    return {
        status:
            'ready',

        projection: {
            scope: {
                type:
                    'tenant',

                tenant_id:
                    '018f3b6a-7c20-7abc-8def-1234567890ab',

                membership_id:
                    '018f3b6a-7c20-7bcd-8def-1234567890ab',
            },

            is_global_superadmin:
                isGlobalSuperadmin,

            permissions,
        },
    };
}

function createWrapper(
    runtime:
        CapabilityRuntime,
) {
    return function Wrapper({
        children,
    }: PropsWithChildren) {
        return (
            <CapabilityContextProvider
                runtime={runtime}
            >
                {children}
            </CapabilityContextProvider>
        );
    };
}

describe(
    'React authorization decision hooks',
    () => {
        it('exposes unresolved Capability authority as pending rather than denied', () => {
            const controlled =
                createControlledCapabilityRuntime();

            const {
                result,
            } =
                renderHook(
                    () =>
                        useAuthorizationDecision(
                            studentsRequirement,
                        ),
                    {
                        wrapper:
                            createWrapper(
                                controlled.runtime,
                            ),
                    },
                );

            expect(
                result.current,
            ).toEqual({
                status:
                    'pending',

                capabilityStatus:
                    'unresolved',
            });

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

        it('reacts to Capability lifecycle changes without dispatching runtime commands', () => {
            const controlled =
                createControlledCapabilityRuntime();

            const {
                result,
            } =
                renderHook(
                    () =>
                        useAuthorizationDecision(
                            studentsRequirement,
                        ),
                    {
                        wrapper:
                            createWrapper(
                                controlled.runtime,
                            ),
                    },
                );

            act(() => {
                controlled.publish({
                    status:
                        'loading',
                });
            });

            expect(
                result.current,
            ).toEqual({
                status:
                    'pending',

                capabilityStatus:
                    'loading',
            });

            act(() => {
                controlled.publish(
                    readyState([
                        studentsView,
                    ]),
                );
            });

            expect(
                result.current,
            ).toEqual({
                status:
                    'allowed',
            });

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

        it('denies only after READY authority proves the required permission is absent', () => {
            const controlled =
                createControlledCapabilityRuntime(
                    readyState([
                        gradesWrite,
                    ]),
                );

            const {
                result,
            } =
                renderHook(
                    () =>
                        useAuthorizationDecision(
                            studentsRequirement,
                        ),
                    {
                        wrapper:
                            createWrapper(
                                controlled.runtime,
                            ),
                    },
                );

            expect(
                result.current,
            ).toEqual({
                status:
                    'denied',
            });
        });

        it('preserves unavailable Capability failure for controlled recovery UX', () => {
            const failure = {
                ok:
                    false as const,

                kind:
                    'network' as const,

                cause:
                    new Error(
                        'offline',
                    ),
            };

            const controlled =
                createControlledCapabilityRuntime({
                    status:
                        'unavailable',

                    failure,
                });

            const {
                result,
            } =
                renderHook(
                    () =>
                        useAuthorizationDecision(
                            studentsRequirement,
                        ),
                    {
                        wrapper:
                            createWrapper(
                                controlled.runtime,
                            ),
                    },
                );

            expect(
                result.current,
            ).toEqual({
                status:
                    'unavailable',

                failure,
            });
        });

        it('inherits canonical ALL and ANY requirement semantics from the platform decision model', () => {
            const controlled =
                createControlledCapabilityRuntime(
                    readyState([
                        studentsView,
                        gradesWrite,
                    ]),
                );

            const {
                result,
            } =
                renderHook(
                    () => {
                        const evaluator =
                            useAuthorizationDecisionEvaluator();

                        return {
                            all:
                                evaluator.evaluate({
                                    mode:
                                        'all',

                                    permissions: [
                                        studentsView,
                                        gradesWrite,
                                    ],
                                }),

                            any:
                                evaluator.evaluate({
                                    mode:
                                        'any',

                                    permissions: [
                                        'dormitory.rooms.manage',
                                        studentsView,
                                    ],
                                }),
                        };
                    },
                    {
                        wrapper:
                            createWrapper(
                                controlled.runtime,
                            ),
                    },
                );

            expect(
                result.current,
            ).toEqual({
                all: {
                    status:
                        'allowed',
                },

                any: {
                    status:
                        'allowed',
                },
            });
        });

        it('never invents access from global superadmin metadata', () => {
            const controlled =
                createControlledCapabilityRuntime(
                    readyState(
                        [],
                        true,
                    ),
                );

            const {
                result,
            } =
                renderHook(
                    () =>
                        useAuthorizationDecision(
                            studentsRequirement,
                        ),
                    {
                        wrapper:
                            createWrapper(
                                controlled.runtime,
                            ),
                    },
                );

            expect(
                result.current,
            ).toEqual({
                status:
                    'denied',
            });
        });
    },
);
