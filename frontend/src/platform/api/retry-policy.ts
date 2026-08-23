import type {
    BrowserApiFailure,
} from '@/platform/api/error';

const MAXIMUM_BROWSER_API_READ_RETRIES =
    2;

const TRANSIENT_BROWSER_API_READ_STATUSES =
    new Set<number>([
        502,
        503,
        504,
    ]);

export function shouldRetryBrowserApiReadFailure(
    failure:
        BrowserApiFailure,
    retryCount:
        number,
): boolean {
    if (
        retryCount < 0
        || retryCount
            >= MAXIMUM_BROWSER_API_READ_RETRIES
    ) {
        return false;
    }

    if (
        failure.kind
            === 'network'
    ) {
        return true;
    }

    if (
        failure.kind
            !== 'response'
    ) {
        return false;
    }

    return TRANSIENT_BROWSER_API_READ_STATUSES
        .has(
            failure.status,
        );
}
