import type { ApiComponents } from '@/platform/api/contract';

export type CanonicalApiError =
    ApiComponents['schemas']['ApiError'];

type GeneratedCanonicalValidationError =
    ApiComponents['schemas']['ValidationError'];

/*
 * OpenAPI currently describes the validation message with
 * its present human-readable literal.
 *
 * Frontend runtime normalization must not treat that copy
 * as stable branching authority, so only the message field
 * is widened to the canonical API message type. Stable
 * status, code, and validation-error structure remain
 * generated-contract derived.
 */
export type CanonicalValidationError =
    Omit<
        GeneratedCanonicalValidationError,
        'message'
    > & {
        readonly message:
            CanonicalApiError['message'];
    };

export type CanonicalValidationErrors =
    ApiComponents['schemas']['ValidationErrors'];

export type CanonicalApiErrorBody =
    | CanonicalApiError
    | CanonicalValidationError;

export interface BrowserApiResponseFailure {
    readonly ok: false;
    readonly kind: 'response';
    readonly status: number;
    readonly error: CanonicalApiErrorBody;
}

export interface BrowserApiProtocolFailure {
    readonly ok: false;
    readonly kind: 'protocol';
    readonly status: number;
    readonly message:
        'EduCore API returned an unexpected error response.';
}

export interface BrowserApiAbortedFailure {
    readonly ok: false;
    readonly kind: 'aborted';
    readonly cause: unknown;
}

export interface BrowserApiNetworkFailure {
    readonly ok: false;
    readonly kind: 'network';
    readonly cause: unknown;
}

export type BrowserApiFailure =
    | BrowserApiResponseFailure
    | BrowserApiProtocolFailure
    | BrowserApiAbortedFailure
    | BrowserApiNetworkFailure;

function isRecord(
    value: unknown,
): value is Record<string, unknown> {
    return (
        typeof value === 'object'
        && value !== null
        && ! Array.isArray(value)
    );
}

function isStringArray(
    value: unknown,
): value is string[] {
    return (
        Array.isArray(value)
        && value.every(
            (item) => typeof item === 'string',
        )
    );
}

function isValidationErrors(
    value: unknown,
): value is CanonicalValidationErrors {
    if (! isRecord(value)) {
        return false;
    }

    return Object.values(value).every(
        isStringArray,
    );
}

export function isCanonicalApiErrorBody(
    value: unknown,
): value is CanonicalApiErrorBody {
    if (! isRecord(value)) {
        return false;
    }

    if (
        value.status !== 'error'
        || typeof value.code !== 'string'
        || typeof value.message !== 'string'
    ) {
        return false;
    }

    if (
        value.code !== 'VALIDATION_FAILED'
    ) {
        return true;
    }

    /*
     * Human-readable backend messages are presentation
     * metadata, not stable frontend branching authority.
     *
     * VALIDATION_FAILED is recognized from its canonical
     * code and validation error structure.
     */
    return isValidationErrors(
        value.errors,
    );
}

export function normalizeBrowserApiResponseFailure(
    response: Response,
    error: unknown,
):
    | BrowserApiResponseFailure
    | BrowserApiProtocolFailure {
    if (
        isCanonicalApiErrorBody(
            error,
        )
    ) {
        return {
            ok: false,
            kind: 'response',
            status: response.status,
            error,
        };
    }

    return {
        ok: false,
        kind: 'protocol',
        status: response.status,
        message:
            'EduCore API returned an unexpected error response.',
    };
}

function isAbortError(
    error: unknown,
): boolean {
    if (
        typeof DOMException !== 'undefined'
        && error instanceof DOMException
        && error.name === 'AbortError'
    ) {
        return true;
    }

    return (
        isRecord(error)
        && error.name === 'AbortError'
    );
}

export function normalizeBrowserApiThrownFailure(
    error: unknown,
):
    | BrowserApiAbortedFailure
    | BrowserApiNetworkFailure {
    if (isAbortError(error)) {
        return {
            ok: false,
            kind: 'aborted',
            cause: error,
        };
    }

    return {
        ok: false,
        kind: 'network',
        cause: error,
    };
}
