export function createBrowserAuthAbortOptions(
    signal: AbortSignal | undefined,
): {
    signal?: AbortSignal;
} {
    if (signal === undefined) {
        return {};
    }

    return {
        signal,
    };
}
