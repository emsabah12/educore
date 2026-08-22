import {
    createBrowserRouter,
    type RouteObject,
} from 'react-router';

import {
    App,
} from '@/app/App';
import {
    NotFoundPage,
} from '@/app/NotFoundPage';
import {
    RouteErrorPage,
} from '@/app/RouteErrorPage';
import {
    LoginRouteBoundary,
} from '@/app/routing/LoginRouteBoundary';
import {
    ProtectedApplicationAccessBoundary,
} from '@/app/routing/ProtectedApplicationAccessBoundary';
import {
    ProtectedApplicationLifecycleBoundary,
} from '@/app/routing/ProtectedApplicationLifecycleBoundary';
import {
    AuthenticatedApplicationShell,
} from '@/app/shell/AuthenticatedApplicationShell';

export const appRoutes = [
    {
        id:
            'protected-application',

        Component:
            ProtectedApplicationLifecycleBoundary,

        children: [
            {
                id:
                    'protected-application-access',

                Component:
                    ProtectedApplicationAccessBoundary,

                children: [
                    {
                        id:
                            'authenticated-application-shell',

                        Component:
                            AuthenticatedApplicationShell,

                        children: [
                            {
                                id:
                                    'root',

                                path:
                                    '/',

                                Component:
                                    App,

                                ErrorBoundary:
                                    RouteErrorPage,
                            },
                        ],
                    },
                ],
            },
        ],
    },
    {
        id:
            'auth.login',

        path:
            '/login',

        Component:
            LoginRouteBoundary,

        ErrorBoundary:
            RouteErrorPage,
    },
    {
        id:
            'not-found',

        path:
            '*',

        Component:
            NotFoundPage,
    },
] satisfies RouteObject[];

export function createAppRouter() {
    return createBrowserRouter(
        appRoutes,
    );
}
