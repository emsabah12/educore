export interface SafeObservabilityMetadata {
    readonly routeId?:
        string;

    readonly module?:
        string;

    readonly releaseId?:
        string;

    readonly environment?:
        string;
}

function readAllowlistedString(
    input:
        Readonly<Record<string, unknown>>,
    key:
        keyof SafeObservabilityMetadata,
): string | undefined {
    const value =
        input[key];

    if (
        typeof value
            !== 'string'
    ) {
        return undefined;
    }

    if (
        value.trim().length
            === 0
    ) {
        return undefined;
    }

    return value;
}

export function createSafeObservabilityMetadata(
    input:
        Readonly<Record<string, unknown>>,
): SafeObservabilityMetadata {
    const routeId =
        readAllowlistedString(
            input,
            'routeId',
        );

    const module =
        readAllowlistedString(
            input,
            'module',
        );

    const releaseId =
        readAllowlistedString(
            input,
            'releaseId',
        );

    const environment =
        readAllowlistedString(
            input,
            'environment',
        );

    return {
        ...(
            routeId === undefined
                ? {}
                : {
                    routeId,
                }
        ),

        ...(
            module === undefined
                ? {}
                : {
                    module,
                }
        ),

        ...(
            releaseId === undefined
                ? {}
                : {
                    releaseId,
                }
        ),

        ...(
            environment === undefined
                ? {}
                : {
                    environment,
                }
        ),
    };
}
