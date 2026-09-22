import {
    executeBrowserApiRequest,
    type BrowserApiClient,
    type BrowserApiResult,
} from '@/platform/api';

import type {
    BrowserLoginRequest,
    BrowserLoginSuccess,
    TenantRegistrationRequest,
    TenantRegistrationSuccess,
} from '@/platform/auth/contract';
import {
    createBrowserAuthAbortOptions,
} from '@/platform/auth/request-options';
import {
    initializeBrowserSession,
} from '@/platform/auth/session-bootstrap';

export interface BrowserLoginOptions {
    readonly signal?:
        AbortSignal;
}

export async function loginWithBrowserSession(
    client: BrowserApiClient,
    request: BrowserLoginRequest,
    options: BrowserLoginOptions = {},
): Promise<
    BrowserApiResult<
        BrowserLoginSuccess
    >
> {
    const abortOptions =
        createBrowserAuthAbortOptions(
            options.signal,
        );

    const sessionResult =
        await initializeBrowserSession(
            client,
            abortOptions,
        );

    if (! sessionResult.ok) {
        return sessionResult;
    }

    return executeBrowserApiRequest(
        client.POST(
            '/api/v1/browser/auth/login',
            {
                ...abortOptions,

                body:
                    request,
            },
        ),
    );
}

export interface TenantRegistrationOptions {
    readonly signal?:
        AbortSignal;
}

/**
 * §Pendaftaran tenant mandiri (self-service) — PARALEL PERSIS
 * dengan loginWithBrowserSession() di atas: sama-sama bootstrap
 * CSRF dulu (endpoint ini state-changing, publik, butuh proteksi
 * request-forgery persis seperti login), lalu POST. Satu-satunya
 * beda: endpoint tujuan dan bentuk request/response.
 */
export async function registerTenantWithBrowserSession(
    client: BrowserApiClient,
    request: TenantRegistrationRequest,
    options: TenantRegistrationOptions = {},
): Promise<
    BrowserApiResult<
        TenantRegistrationSuccess
    >
> {
    const abortOptions =
        createBrowserAuthAbortOptions(
            options.signal,
        );

    const sessionResult =
        await initializeBrowserSession(
            client,
            abortOptions,
        );

    if (! sessionResult.ok) {
        return sessionResult;
    }

    return executeBrowserApiRequest(
        client.POST(
            '/api/v1/browser/auth/register',
            {
                ...abortOptions,

                body:
                    request,
            },
        ),
    );
}
