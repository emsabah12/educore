import {
    describe,
    expect,
    it,
    vi,
} from 'vitest';

interface TestSafeObservabilityMetadata {
    readonly routeId?:
        string;

    readonly module?:
        string;

    readonly releaseId?:
        string;

    readonly environment?:
        string;
}

interface TestSafeObservabilityException {
    readonly kind:
        'error'
        | 'unknown';
}

interface TestObservabilitySignal {
    readonly kind:
        'event'
        | 'exception';

    readonly name:
        string;

    readonly metadata:
        TestSafeObservabilityMetadata;

    readonly exception?:
        TestSafeObservabilityException;
}

interface TestObservabilityAdapter {
    capture(
        signal:
            TestObservabilitySignal,
    ): void;
}

interface TestObservabilityPort {
    captureEvent(
        name:
            string,
        metadata?:
            Readonly<
                Record<
                    string,
                    unknown
                >
            >,
    ): void;

    captureException(
        name:
            string,
        error:
            unknown,
        metadata?:
            Readonly<
                Record<
                    string,
                    unknown
                >
            >,
    ): void;
}

interface ObservabilityPortModule {
    readonly createObservabilityPort?:
        (
            adapter:
                TestObservabilityAdapter,
        ) => TestObservabilityPort;
}

describe(
    'Vendor-neutral observability port',
    () => {
        it('forwards only allowlisted metadata to the provider adapter', async () => {
            const modulePath =
                '../port';

            const portModule =
                await import(
                    modulePath
                ) as ObservabilityPortModule;

            const createPort =
                portModule
                    .createObservabilityPort;

            expect(
                createPort,
            ).toBeTypeOf(
                'function',
            );

            if (
                createPort === undefined
            ) {
                return;
            }

            const capture =
                vi.fn();

            const port =
                createPort({
                    capture,
                });

            expect(
                port.captureEvent,
            ).toBeTypeOf(
                'function',
            );

            expect(
                port.captureException,
            ).toBeTypeOf(
                'function',
            );

            port.captureEvent(
                'workspace_switch_failed',
                {
                    routeId:
                        'core.workspace',

                    module:
                        'platform',

                    releaseId:
                        'test-release',

                    environment:
                        'test',

                    authorization:
                        'Bearer secret-token',

                    cookie:
                        'educore_session=secret',

                    password:
                        'secret-password',

                    workspacePayload: {
                        organizationName:
                            'Sensitive Organization',
                    },

                    rawUrl:
                        '/workspace/private-id?search=secret',
                },
            );

            expect(
                capture,
            ).toHaveBeenCalledTimes(
                1,
            );

            expect(
                capture,
            ).toHaveBeenCalledWith({
                kind:
                    'event',

                name:
                    'workspace_switch_failed',

                metadata: {
                    routeId:
                        'core.workspace',

                    module:
                        'platform',

                    releaseId:
                        'test-release',

                    environment:
                        'test',
                },
            });

            const serializedSignal =
                JSON.stringify(
                    capture.mock
                        .calls[0]?.[0],
                );

            expect(
                serializedSignal,
            ).not.toContain(
                'secret-token',
            );

            expect(
                serializedSignal,
            ).not.toContain(
                'educore_session',
            );

            expect(
                serializedSignal,
            ).not.toContain(
                'secret-password',
            );

            expect(
                serializedSignal,
            ).not.toContain(
                'Sensitive Organization',
            );

            expect(
                serializedSignal,
            ).not.toContain(
                'private-id',
            );
        });

        it('does not propagate event adapter failure into product flow', async () => {
            const modulePath =
                '../port';

            const portModule =
                await import(
                    modulePath
                ) as ObservabilityPortModule;

            const createPort =
                portModule
                    .createObservabilityPort;

            expect(
                createPort,
            ).toBeTypeOf(
                'function',
            );

            if (
                createPort === undefined
            ) {
                return;
            }

            const capture =
                vi.fn(
                    () => {
                        throw new Error(
                            'Synthetic telemetry provider failure.',
                        );
                    },
                );

            const port =
                createPort({
                    capture,
                });

            expect(
                () =>
                    port.captureEvent(
                        'workspace_switch_failed',
                        {
                            routeId:
                                'core.workspace',
                        },
                    ),
            ).not.toThrow();

            expect(
                capture,
            ).toHaveBeenCalledTimes(
                1,
            );
        });

        it('does not propagate exception adapter failure into product flow', async () => {
            const modulePath =
                '../port';

            const portModule =
                await import(
                    modulePath
                ) as ObservabilityPortModule;

            const createPort =
                portModule
                    .createObservabilityPort;

            expect(
                createPort,
            ).toBeTypeOf(
                'function',
            );

            if (
                createPort === undefined
            ) {
                return;
            }

            const capture =
                vi.fn(
                    () => {
                        throw new Error(
                            'Synthetic telemetry provider failure.',
                        );
                    },
                );

            const port =
                createPort({
                    capture,
                });

            expect(
                () =>
                    port.captureException(
                        'application_render_failed',
                        new Error(
                            'Synthetic application error.',
                        ),
                        {
                            module:
                                'platform',
                        },
                    ),
            ).not.toThrow();

            expect(
                capture,
            ).toHaveBeenCalledTimes(
                1,
            );
        });

        it('normalizes a raw throwable before adapter delivery', async () => {
            const modulePath =
                '../port';

            const portModule =
                await import(
                    modulePath
                ) as ObservabilityPortModule;

            const createPort =
                portModule
                    .createObservabilityPort;

            expect(
                createPort,
            ).toBeTypeOf(
                'function',
            );

            if (
                createPort === undefined
            ) {
                return;
            }

            const capture =
                vi.fn();

            const port =
                createPort({
                    capture,
                });

            const error =
                new Error(
                    'Sensitive Student secret-id',
                );

            error.stack =
                'Error: Sensitive Student secret-id at /private/app.js';

            Object.assign(
                error,
                {
                    authorization:
                        'Bearer secret-token',

                    cookie:
                        'educore_session=secret',

                    studentPayload: {
                        medicalNote:
                            'private-domain-payload',
                    },
                },
            );

            port.captureException(
                'application_render_failed',
                error,
                {
                    module:
                        'platform',

                    environment:
                        'test',

                    password:
                        'secret-password',

                    rawUrl:
                        '/student/private-id',
                },
            );

            expect(
                capture,
            ).toHaveBeenCalledTimes(
                1,
            );

            expect(
                capture,
            ).toHaveBeenCalledWith({
                kind:
                    'exception',

                name:
                    'application_render_failed',

                metadata: {
                    module:
                        'platform',

                    environment:
                        'test',
                },

                exception: {
                    kind:
                        'error',
                },
            });

            const serializedSignal =
                JSON.stringify(
                    capture.mock
                        .calls[0]?.[0],
                );

            expect(
                serializedSignal,
            ).not.toContain(
                'Sensitive Student',
            );

            expect(
                serializedSignal,
            ).not.toContain(
                'secret-id',
            );

            expect(
                serializedSignal,
            ).not.toContain(
                'secret-token',
            );

            expect(
                serializedSignal,
            ).not.toContain(
                'educore_session',
            );

            expect(
                serializedSignal,
            ).not.toContain(
                'private-domain-payload',
            );

            expect(
                serializedSignal,
            ).not.toContain(
                'secret-password',
            );

            expect(
                serializedSignal,
            ).not.toContain(
                'private-id',
            );
        });
    },
);
