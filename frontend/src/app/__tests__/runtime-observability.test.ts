import {
    afterEach,
    describe,
    expect,
    it,
    vi,
} from 'vitest';

const observability = vi.hoisted(
    () => ({
        captureEvent:
            vi.fn(),

        captureException:
            vi.fn(),
    }),
);

const recoveryReporter = vi.hoisted(
    () => ({
        reportFailure:
            null as
                | ((
                    error:
                        unknown,
                ) => void)
                | null,
    }),
);

vi.mock(
    '@/platform/observability/runtime',
    () => ({
        createNoopObservabilityPort() {
            return {
                captureEvent:
                    observability.captureEvent,

                captureException:
                    observability.captureException,
            };
        },
    }),
);

vi.mock(
    '@/app/authorization/capability-workspace-recovery',
    () => ({
        createCapabilityWorkspaceRecoveryCoordinator(
            _capabilities:
                unknown,
            _workspace:
                unknown,
            options: {
                readonly reportFailure:
                    (
                        error:
                            unknown,
                    ) => void;
            },
        ) {
            recoveryReporter.reportFailure =
                options.reportFailure;

            return {
                dispose:
                    vi.fn(),
            };
        },
    }),
);

afterEach(
    () => {
        observability.captureEvent
            .mockClear();

        observability.captureException
            .mockClear();

        recoveryReporter.reportFailure =
            null;

        vi.restoreAllMocks();
        vi.resetModules();
    },
);

describe(
    'Application runtime observability',
    () => {
        it('routes exceptional coordination failures through the owned observability port without raw console reporting', async () => {
            const consoleError =
                vi.spyOn(
                    console,
                    'error',
                )
                    .mockImplementation(
                        () => undefined,
                    );

            const {
                createApplicationRuntime,
            } = await import(
                '@/app/runtime'
            );

            const runtime =
                createApplicationRuntime();

            expect(
                recoveryReporter
                    .reportFailure,
            ).toBeTypeOf(
                'function',
            );

            const failure =
                Object.assign(
                    new Error(
                        'Sensitive Student secret-id',
                    ),
                    {
                        authorization:
                            'Bearer secret-token',

                        cookie:
                            'browser_session=secret',

                        studentPayload: {
                            id:
                                'student-secret-id',
                        },
                    },
                );

            recoveryReporter
                .reportFailure?.(
                    failure,
                );

            expect(
                observability
                    .captureException,
            ).toHaveBeenCalledTimes(
                1,
            );

            expect(
                observability
                    .captureException,
            ).toHaveBeenCalledWith(
                'application_runtime_coordination_failed',
                failure,
                {
                    module:
                        'application',
                },
            );

            expect(
                consoleError,
            ).not.toHaveBeenCalled();

            runtime.dispose();
        });
    },
);
