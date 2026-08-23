import {
    normalizeBrowserApiResponseFailure,
    normalizeBrowserApiThrownFailure,
    type BrowserApiFailure,
} from '@/platform/api/error';
import {
    shouldRetryBrowserApiReadFailure,
} from '@/platform/api/retry-policy';

interface BrowserApiRawResult<TData> {
    readonly data?: TData;
    readonly error?: unknown;
    readonly response: Response;
}

export interface BrowserApiSuccess<TData> {
    readonly ok: true;
    readonly status: number;
    readonly data: TData | undefined;
}

export type BrowserApiResult<TData> =
    | BrowserApiSuccess<TData>
    | BrowserApiFailure;

export async function executeBrowserApiRequest<TData>(
    request: Promise<
        BrowserApiRawResult<TData>
    >,
): Promise<BrowserApiResult<TData>> {
    try {
        const result = await request;

        if (
            result.response.ok
            && result.error === undefined
        ) {
            return {
                ok: true,
                status: result.response.status,
                data: result.data,
            };
        }

        return normalizeBrowserApiResponseFailure(
            result.response,
            result.error,
        );
    } catch (error: unknown) {
        return normalizeBrowserApiThrownFailure(
            error,
        );
    }
}


export async function executeBrowserApiReadRequest<TData>(
    requestFactory:
        () => Promise<
            BrowserApiRawResult<TData>
        >,
): Promise<BrowserApiResult<TData>> {
    let retryCount =
        0;

    while (true) {
        /*
         * Each iteration invokes the factory again so a retry
         * always represents a fresh HTTP attempt rather than
         * awaiting the same already-started Promise.
         *
         * Promise.resolve().then(...) also converts a
         * synchronous factory exception into the existing
         * normalized thrown-failure boundary.
         */
        const result =
            await executeBrowserApiRequest(
                Promise
                    .resolve()
                    .then(
                        requestFactory,
                    ),
            );

        if (result.ok) {
            return result;
        }

        if (
            ! shouldRetryBrowserApiReadFailure(
                result,
                retryCount,
            )
        ) {
            return result;
        }

        retryCount +=
            1;
    }
}
