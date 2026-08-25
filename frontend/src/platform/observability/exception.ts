export type SafeObservabilityExceptionKind =
    | 'error'
    | 'unknown';

export interface SafeObservabilityException {
    readonly kind:
        SafeObservabilityExceptionKind;
}

export function normalizeObservabilityException(
    error:
        unknown,
): SafeObservabilityException {
    if (
        error instanceof Error
    ) {
        return {
            kind:
                'error',
        };
    }

    return {
        kind:
            'unknown',
    };
}
