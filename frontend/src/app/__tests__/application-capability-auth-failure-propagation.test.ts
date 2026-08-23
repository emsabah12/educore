import {
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vitest';

const propagationMocks =
    vi.hoisted(
        () => {
            const dispose =
                vi.fn();

            const createCoordinator =
                vi.fn(
                    () => ({
                        dispose,
                    }),
                );

            return {
                createCoordinator,
                dispose,
            };
        },
    );

vi.mock(
    '@/app/authorization/capability-auth-failure-propagation',
    () => ({
        createCapabilityAuthFailurePropagationCoordinator:
            propagationMocks
                .createCoordinator,
    }),
);

import {
    createApplicationRuntime,
} from '@/app/runtime';

describe(
    'Application Capability to authentication failure propagation',
    () => {
        beforeEach(
            () => {
                propagationMocks
                    .createCoordinator
                    .mockClear();

                propagationMocks
                    .dispose
                    .mockClear();
            },
        );

        it('composes Capability failure propagation with the canonical application Auth runtime', () => {
            const runtime =
                createApplicationRuntime();

            expect(
                propagationMocks
                    .createCoordinator,
            ).toHaveBeenCalledTimes(
                1,
            );

            expect(
                propagationMocks
                    .createCoordinator,
            ).toHaveBeenCalledWith(
                runtime.capabilities,
                runtime.auth,
            );

            runtime.dispose();

            expect(
                propagationMocks
                    .dispose,
            ).toHaveBeenCalledTimes(
                1,
            );
        });

        it('disposes Capability authentication propagation only once with aggregate runtime disposal', () => {
            const runtime =
                createApplicationRuntime();

            runtime.dispose();
            runtime.dispose();

            expect(
                propagationMocks
                    .dispose,
            ).toHaveBeenCalledTimes(
                1,
            );
        });
    },
);
