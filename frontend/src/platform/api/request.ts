import {
    normalizeBrowserApiResponseFailure,
    normalizeBrowserApiThrownFailure,
    type BrowserApiFailure,
} from '@/platform/api/error';

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
