import type {
    BrowserApiFailure,
    BrowserApiResponseFailure,
} from '@/platform/api';

export function isBrowserSessionAuthenticationRequiredFailure(
    failure: BrowserApiFailure,
): failure is BrowserApiResponseFailure {
    return (
        failure.kind === 'response'
        && failure.status === 401
        && failure.error.code
            === 'BROWSER_SESSION_AUTHENTICATION_REQUIRED'
    );
}

export function isBrowserMembershipContextRequiredFailure(
    failure: BrowserApiFailure,
): failure is BrowserApiResponseFailure {
    return (
        failure.kind === 'response'
        && failure.status === 403
        && failure.error.code
            === 'BROWSER_MEMBERSHIP_CONTEXT_REQUIRED'
    );
}
