import {
    render,
    screen,
} from '@testing-library/react';
import {
    describe,
    expect,
    it,
    vi,
} from 'vitest';

import {
    ApplicationErrorBoundary,
} from '@/app/ApplicationErrorBoundary';
import type {
    ObservabilityPort,
} from '@/platform/observability/port';

const renderFailure =
    new Error(
        'Synthetic render failure',
    );

function BrokenComponent():
    never {
    throw renderFailure;
}

describe(
    'ApplicationErrorBoundary',
    () => {
        it('fails closed and reports the render exception through observability without component-stack telemetry', () => {
            const observability:
                ObservabilityPort = {
                    captureEvent:
                        vi.fn(),

                    captureException:
                        vi.fn(),
                };

            const consoleError =
                vi.spyOn(
                    console,
                    'error',
                )
                    .mockImplementation(
                        () => undefined,
                    );

            render(
                <ApplicationErrorBoundary
                    observability={
                        observability
                    }
                >
                    <BrokenComponent />
                </ApplicationErrorBoundary>,
            );

            expect(
                screen.getByRole(
                    'heading',
                    {
                        name:
                            'Aplikasi tidak dapat dimuat',
                    },
                ),
            ).toBeInTheDocument();

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
                'application_render_failed',
                renderFailure,
                {
                    module:
                        'application',
                },
            );

            expect(
                observability
                    .captureEvent,
            ).not.toHaveBeenCalled();

            /*
             * React itself may write development diagnostics
             * for a render exception. Reject only EduCore's
             * legacy production console reporter.
             */
            expect(
                consoleError.mock.calls
                    .some(
                        (
                            [
                                firstArgument,
                            ],
                        ) =>
                            firstArgument
                                === 'EduCore application render failed.',
                    ),
            ).toBe(
                false,
            );

            consoleError
                .mockRestore();
        });
    },
);
