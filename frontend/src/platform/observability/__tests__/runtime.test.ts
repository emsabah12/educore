import {
    afterEach,
    describe,
    expect,
    it,
    vi,
} from 'vitest';

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

interface ObservabilityRuntimeModule {
    readonly createNoopObservabilityPort?:
        () => TestObservabilityPort;
}

describe(
    'Observability runtime',
    () => {
        afterEach(
            () => {
                vi.restoreAllMocks();
            },
        );

        it('provides a silent no-op port when no telemetry provider is configured', async () => {
            const consoleError =
                vi.spyOn(
                    console,
                    'error',
                ).mockImplementation(
                    () => undefined,
                );

            const consoleWarn =
                vi.spyOn(
                    console,
                    'warn',
                ).mockImplementation(
                    () => undefined,
                );

            const modulePath =
                '../runtime';

            const runtimeModule =
                await import(
                    modulePath
                ) as ObservabilityRuntimeModule;

            const createNoopPort =
                runtimeModule
                    .createNoopObservabilityPort;

            expect(
                createNoopPort,
            ).toBeTypeOf(
                'function',
            );

            if (
                createNoopPort
                    === undefined
            ) {
                return;
            }

            const port =
                createNoopPort();

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

            expect(
                () => {
                    port.captureEvent(
                        'application_bootstrap_started',
                        {
                            module:
                                'platform',

                            environment:
                                'test',

                            password:
                                'secret-password',
                        },
                    );

                    port.captureException(
                        'application_bootstrap_failed',
                        new Error(
                            'Sensitive Student secret-id',
                        ),
                        {
                            module:
                                'platform',

                            environment:
                                'test',

                            authorization:
                                'Bearer secret-token',
                        },
                    );
                },
            ).not.toThrow();

            expect(
                consoleError,
            ).not.toHaveBeenCalled();

            expect(
                consoleWarn,
            ).not.toHaveBeenCalled();
        });
    },
);
