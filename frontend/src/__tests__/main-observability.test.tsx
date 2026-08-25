import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vitest';

const entrypointMocks =
    vi.hoisted(
        () => {
            const observability = {
                captureEvent:
                    vi.fn(),

                captureException:
                    vi.fn(),
            };

            const runtime = {
                observability,

                dispose:
                    vi.fn(),
            };

            return {
                observability,
                runtime,

                createApplicationRuntime:
                    vi.fn(
                        () =>
                            runtime,
                    ),

                createBrowserRuntimeObservabilityCoordinator:
                    vi.fn(
                        () => ({
                            dispose:
                                vi.fn(),
                        }),
                    ),

                rootRender:
                    vi.fn(),

                rootUnmount:
                    vi.fn(),
            };
        },
    );

vi.mock(
    '@/app/runtime',
    () => ({
        createApplicationRuntime:
            entrypointMocks
                .createApplicationRuntime,
    }),
);

vi.mock(
    '@/platform/observability/runtime',
    () => ({
        createBrowserRuntimeObservabilityCoordinator:
            entrypointMocks
                .createBrowserRuntimeObservabilityCoordinator,
    }),
);

vi.mock(
    '@/app/AppBootstrap',
    () => ({
        AppBootstrap() {
            return null;
        },
    }),
);

vi.mock(
    'react-dom/client',
    () => ({
        createRoot() {
            return {
                render:
                    entrypointMocks
                        .rootRender,

                unmount:
                    entrypointMocks
                        .rootUnmount,
            };
        },
    }),
);

describe(
    'Frontend entrypoint observability ownership',
    () => {
        beforeEach(
            () => {
                vi.resetModules();

                entrypointMocks
                    .createApplicationRuntime
                    .mockClear();

                entrypointMocks
                    .createBrowserRuntimeObservabilityCoordinator
                    .mockClear();

                entrypointMocks
                    .rootRender
                    .mockClear();

                entrypointMocks
                    .rootUnmount
                    .mockClear();

                entrypointMocks
                    .runtime
                    .dispose
                    .mockClear();

                document.body.innerHTML =
                    '<div id="root"></div>';
            },
        );

        afterEach(
            () => {
                document.body.innerHTML =
                    '';
            },
        );

        it('creates exactly one browser observability coordinator from the application runtime port', async () => {
            await import(
                '@/main'
            );

            expect(
                entrypointMocks
                    .createApplicationRuntime,
            ).toHaveBeenCalledTimes(
                1,
            );

            expect(
                entrypointMocks
                    .createBrowserRuntimeObservabilityCoordinator,
            ).toHaveBeenCalledTimes(
                1,
            );

            expect(
                entrypointMocks
                    .createBrowserRuntimeObservabilityCoordinator,
            ).toHaveBeenCalledWith(
                window,
                entrypointMocks
                    .observability,
                {
                    module:
                        'application',
                },
            );

            expect(
                entrypointMocks
                    .rootRender,
            ).toHaveBeenCalledTimes(
                1,
            );
        });

        it('disposes React, browser observability, and the application runtime in deterministic order', async () => {
            const mainModule =
                await import(
                    '@/main'
                );

            const disposeResources =
                mainModule
                    .disposeFrontendEntrypointResources;

            const disposalOrder:
                string[] = [];

            const root = {
                    unmount:
                        vi.fn(
                            () => {
                                disposalOrder
                                    .push(
                                        'react',
                                    );
                            },
                        ),
                };

            const browserObservabilityCoordinator = {
                    dispose:
                        vi.fn(
                            () => {
                                disposalOrder
                                    .push(
                                        'browser-observability',
                                    );
                            },
                        ),
                };

            const runtime = {
                    dispose:
                        vi.fn(
                            () => {
                                disposalOrder
                                    .push(
                                        'application-runtime',
                                    );
                            },
                        ),
                };

            disposeResources(
                root,
                browserObservabilityCoordinator,
                runtime,
            );

            expect(
                disposalOrder,
            ).toEqual([
                'react',
                'browser-observability',
                'application-runtime',
            ]);

            expect(
                root.unmount,
            ).toHaveBeenCalledTimes(
                1,
            );

            expect(
                browserObservabilityCoordinator
                    .dispose,
            ).toHaveBeenCalledTimes(
                1,
            );

            expect(
                runtime.dispose,
            ).toHaveBeenCalledTimes(
                1,
            );
        });
    },
);
