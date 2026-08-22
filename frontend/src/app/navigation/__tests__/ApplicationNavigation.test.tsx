import {
    render,
    screen,
} from '@testing-library/react';
import {
    createMemoryRouter,
} from 'react-router';
import {
    RouterProvider,
} from 'react-router/dom';
import {
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vitest';

import type {
    ApplicationNavigationProjection,
} from '@/app/navigation/navigation-projection';

const mocks =
    vi.hoisted<{
        projection:
            readonly ApplicationNavigationProjection[];
    }>(
        () => ({
            projection:
                [],
        }),
    );

vi.mock(
    '@/app/navigation/useApplicationNavigationProjection',
    () => ({
        useApplicationNavigationProjection:
            () =>
                mocks.projection,
    }),
);

import {
    ApplicationNavigation,
} from '@/app/navigation/ApplicationNavigation';

function renderNavigation(
    initialEntry:
        string = '/',
) {
    const router =
        createMemoryRouter(
            [
                {
                    path:
                        '*',

                    element: (
                        <ApplicationNavigation />
                    ),
                },
            ],
            {
                initialEntries: [
                    initialEntry,
                ],
            },
        );

    render(
        <RouterProvider
            router={router}
        />,
    );

    return router;
}

describe(
    'ApplicationNavigation',
    () => {
        beforeEach(
            () => {
                mocks.projection =
                    [];
            },
        );

        it('renders only navigation entries projected as visible', () => {
            mocks.projection = [
                {
                    status:
                        'visible',

                    navigation: {
                        id:
                            'application.home',

                        routeId:
                            'root',

                        label:
                            'Beranda',

                        destination:
                            '/',
                    },
                },
                {
                    status:
                        'hidden',

                    navigation: {
                        id:
                            'test.hidden',

                        routeId:
                            'test.hidden',

                        label:
                            'Hidden destination',

                        destination:
                            '/hidden',
                    },

                    reason:
                        'permission-denied',
                },
            ];

            renderNavigation();

            expect(
                screen.getByRole(
                    'navigation',
                    {
                        name:
                            'Navigasi utama',
                    },
                ),
            ).toBeInTheDocument();

            expect(
                screen.getByRole(
                    'link',
                    {
                        name:
                            'Beranda',
                    },
                ),
            ).toHaveAttribute(
                'href',
                '/',
            );

            expect(
                screen.queryByRole(
                    'link',
                    {
                        name:
                            'Hidden destination',
                    },
                ),
            ).not.toBeInTheDocument();
        });

        it('marks the exact current root destination as active', () => {
            mocks.projection = [
                {
                    status:
                        'visible',

                    navigation: {
                        id:
                            'application.home',

                        routeId:
                            'root',

                        label:
                            'Beranda',

                        destination:
                            '/',
                    },
                },
            ];

            renderNavigation(
                '/',
            );

            expect(
                screen.getByRole(
                    'link',
                    {
                        name:
                            'Beranda',
                    },
                ),
            ).toHaveAttribute(
                'aria-current',
                'page',
            );
        });

        it('does not keep the root entry active for another application location', () => {
            mocks.projection = [
                {
                    status:
                        'visible',

                    navigation: {
                        id:
                            'application.home',

                        routeId:
                            'root',

                        label:
                            'Beranda',

                        destination:
                            '/',
                    },
                },
            ];

            renderNavigation(
                '/future-route',
            );

            expect(
                screen.getByRole(
                    'link',
                    {
                        name:
                            'Beranda',
                    },
                ),
            ).not.toHaveAttribute(
                'aria-current',
                'page',
            );
        });

        it('does not render an empty navigation landmark when no entry is visible', () => {
            mocks.projection = [
                {
                    status:
                        'hidden',

                    navigation: {
                        id:
                            'application.home',

                        routeId:
                            'root',

                        label:
                            'Beranda',

                        destination:
                            '/',
                    },

                    reason:
                        'authority-pending',
                },
            ];

            renderNavigation();

            expect(
                screen.queryByRole(
                    'navigation',
                    {
                        name:
                            'Navigasi utama',
                    },
                ),
            ).not.toBeInTheDocument();
        });

        it('keeps visible navigation in a non-wrapping row suitable for horizontal overflow', () => {
            mocks.projection = [
                {
                    status:
                        'visible',

                    navigation: {
                        id:
                            'application.home',

                        routeId:
                            'root',

                        label:
                            'Beranda',

                        destination:
                            '/',
                    },
                },
            ];

            renderNavigation();

            expect(
                screen.getByRole(
                    'navigation',
                    {
                        name:
                            'Navigasi utama',
                    },
                ),
            ).toHaveClass(
                'min-w-max',
            );

            expect(
                screen.getByRole(
                    'list',
                ),
            ).toHaveClass(
                'flex',
                'min-w-max',
                'items-center',
                'gap-2',
            );
        });
    },
);