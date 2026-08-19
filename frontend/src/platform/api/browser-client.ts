import createClient, {
    type Middleware,
} from 'openapi-fetch';

import type { ApiPaths } from '@/platform/api/contract';
import { applyRequestForgeryHeader } from '@/platform/api/request-forgery';

const browserSecurityMiddleware: Middleware = {
    onRequest({
        request,
    }) {
        const headers = new Headers(
            request.headers,
        );

        /*
         * This client is exclusively for the first-party
         * BrowserSession transport.
         *
         * Canonical bearer credentials remain server-side
         * and must never be supplied by browser JavaScript.
         */
        headers.delete(
            'Authorization',
        );

        applyRequestForgeryHeader(
            headers,
            request.method,
        );

        return new Request(
            request,
            {
                headers,
            },
        );
    },
};

function resolveSameOriginBaseUrl(): string {
    if (typeof window === 'undefined') {
        throw new Error(
            'EduCore Browser API client requires a browser runtime.',
        );
    }

    return window.location.origin;
}

export function createBrowserApiClient() {
    const client = createClient<ApiPaths>({
        baseUrl: resolveSameOriginBaseUrl(),
        credentials: 'same-origin',
    });

    client.use(
        browserSecurityMiddleware,
    );

    return client;
}

export type BrowserApiClient = ReturnType<
    typeof createBrowserApiClient
>;
