import type {
    BrowserApiClient,
    BrowserApiResult,
} from '@/platform/api';

import {
    bootstrapBrowserAuthentication,
    type BrowserAuthBootstrapOptions,
} from '@/platform/auth/bootstrap';
import type {
    AuthenticatedBootstrapSuccess,
    BrowserLoginRequest,
    BrowserLoginSuccess,
    BrowserLogoutSuccess,
} from '@/platform/auth/contract';
import {
    logoutBrowserSession,
    type BrowserLogoutOptions,
} from '@/platform/auth/logout';
import {
    loginWithBrowserSession,
    type BrowserLoginOptions,
} from '@/platform/auth/service';

export interface BrowserAuthOperations {
    bootstrap(
        options?: BrowserAuthBootstrapOptions,
    ): Promise<
        BrowserApiResult<
            AuthenticatedBootstrapSuccess
        >
    >;

    login(
        request: BrowserLoginRequest,
        options?: BrowserLoginOptions,
    ): Promise<
        BrowserApiResult<
            BrowserLoginSuccess
        >
    >;

    logout(
        options?: BrowserLogoutOptions,
    ): Promise<
        BrowserApiResult<
            BrowserLogoutSuccess
        >
    >;
}

export function createBrowserAuthOperations(
    client: BrowserApiClient,
): BrowserAuthOperations {
    return {
        bootstrap(
            options,
        ) {
            return bootstrapBrowserAuthentication(
                client,
                options,
            );
        },

        login(
            request,
            options,
        ) {
            return loginWithBrowserSession(
                client,
                request,
                options,
            );
        },

        logout(
            options,
        ) {
            return logoutBrowserSession(
                client,
                options,
            );
        },
    };
}
