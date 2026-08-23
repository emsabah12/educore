import {
    act,
    render,
    screen,
} from '@testing-library/react';
import {
    createMemoryRouter,
    Outlet,
} from 'react-router';
import {
    RouterProvider,
} from 'react-router/dom';
import {
    afterEach,
    describe,
    expect,
    it,
    vi,
} from 'vitest';

import {
    RouteErrorPage,
} from '@/app/RouteErrorPage';

function TestApplicationShell() {
    return (
        <div>
            <header>
                Shell tetap tersedia
            </header>

            <main>
                <Outlet />
            </main>
        </div>
    );
}

function StableHomePage() {
    return (
        <h1>
            Beranda stabil
        </h1>
    );
}

function TestModuleRouteBoundary() {
    return (
        <section>
            <Outlet />
        </section>
    );
}

describe(
    'Lazy route runtime error isolation',
    () => {
        afterEach(
            () => {
                vi.restoreAllMocks();
            },
        );

        it('isolates a lazy child failure inside a static module route boundary while preserving the application shell', async () => {
            vi.spyOn(
                console,
                'error',
            ).mockImplementation(
                () => undefined,
            );

            const lazyModule =
                vi.fn(
                    async () => {
                        throw new Error(
                            'sensitive lazy chunk transport detail',
                        );
                    },
                );

            const router =
                createMemoryRouter(
                    [
                        {
                            id:
                                'test.application-shell',

                            Component:
                                TestApplicationShell,

                            children: [
                                {
                                    id:
                                        'test.home',

                                    index:
                                        true,

                                    Component:
                                        StableHomePage,
                                },
                                {
                                    id:
                                        'test.module-boundary',

                                    path:
                                        'lazy-module',

                                    Component:
                                        TestModuleRouteBoundary,

                                    ErrorBoundary:
                                        RouteErrorPage,

                                    children: [
                                        {
                                            id:
                                                'test.lazy-module-page',

                                            index:
                                                true,

                                            lazy:
                                                lazyModule,
                                        },
                                    ],
                                },
                            ],
                        },
                    ],
                    {
                        initialEntries: [
                            '/',
                        ],
                    },
                );

            render(
                <RouterProvider
                    router={
                        router
                    }
                />,
            );

            expect(
                screen.getByText(
                    'Shell tetap tersedia',
                ),
            ).toBeInTheDocument();

            expect(
                screen.getByRole(
                    'heading',
                    {
                        name:
                            'Beranda stabil',
                    },
                ),
            ).toBeInTheDocument();

            await act(
                async () => {
                    await router.navigate(
                        '/lazy-module?tab=active#details',
                    );
                },
            );

            expect(
                await screen.findByRole(
                    'heading',
                    {
                        name:
                            'Halaman tidak dapat dimuat',
                    },
                ),
            ).toBeInTheDocument();

            expect(
                screen.getByText(
                    'Shell tetap tersedia',
                ),
            ).toBeInTheDocument();

            expect(
                screen.queryByRole(
                    'heading',
                    {
                        name:
                            'Beranda stabil',
                    },
                ),
            ).not.toBeInTheDocument();

            expect(
                screen.queryByText(
                    'sensitive lazy chunk transport detail',
                ),
            ).not.toBeInTheDocument();

            expect(
                lazyModule,
            ).toHaveBeenCalledTimes(
                1,
            );

            expect(
                screen.getByRole(
                    'link',
                    {
                        name:
                            'Muat ulang aplikasi',
                    },
                ),
            ).toHaveAttribute(
                'href',
                '/lazy-module?tab=active#details',
            );
        });
    },
);
