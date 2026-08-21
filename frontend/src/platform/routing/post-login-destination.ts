import {
    parseSafeInternalReturnDestination,
    type SafeInternalReturnDestination,
} from '@/platform/routing/return-destination';

const APPLICATION_ENTRY_DESTINATION =
    '/' as SafeInternalReturnDestination;

function pathnameOf(
    destination:
        SafeInternalReturnDestination,
): string {
    const queryIndex =
        destination.indexOf(
            '?',
        );

    const hashIndex =
        destination.indexOf(
            '#',
        );

    const separatorIndexes = [
        queryIndex,
        hashIndex,
    ].filter(
        (
            index,
        ): index is number =>
            index >= 0,
    );

    if (
        separatorIndexes.length
            === 0
    ) {
        return destination;
    }

    const firstSeparator =
        Math.min(
            ...separatorIndexes,
        );

    return destination.slice(
        0,
        firstSeparator,
    );
}

function isLoginSelfDestination(
    destination:
        SafeInternalReturnDestination,
): boolean {
    const pathname =
        pathnameOf(
            destination,
        );

    return (
        pathname === '/login'
        || pathname === '/login/'
    );
}

export function resolvePostLoginDestination(
    value:
        string | null | undefined,
): SafeInternalReturnDestination {
    const destination =
        parseSafeInternalReturnDestination(
            value,
        );

    if (
        destination === null
        || isLoginSelfDestination(
            destination,
        )
    ) {
        return APPLICATION_ENTRY_DESTINATION;
    }

    return destination;
}
