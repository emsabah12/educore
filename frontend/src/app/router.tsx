import {
    createBrowserRouter,
    type RouteObject,
} from 'react-router';

import { App } from '@/app/App';
import { NotFoundPage } from '@/app/NotFoundPage';
import { RouteErrorPage } from '@/app/RouteErrorPage';

const routes = [
    {
        id: 'root',
        path: '/',
        Component: App,
        ErrorBoundary: RouteErrorPage,
    },
    {
        id: 'not-found',
        path: '*',
        Component: NotFoundPage,
    },
] satisfies RouteObject[];

export function createAppRouter() {
    return createBrowserRouter(routes);
}
