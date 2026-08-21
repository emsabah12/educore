export type SafeInternalReturnDestination =
    string & {
        readonly __safeInternalReturnDestination:
            unique symbol;
    };

function containsControlCharacter(
    value:
        string,
): boolean {
    for (
        const character
        of value
    ) {
        const codePoint =
            character.codePointAt(
                0,
            );

        if (
            codePoint !== undefined
            && (
                codePoint <= 0x1f
                || codePoint === 0x7f
            )
        ) {
            return true;
        }
    }

    return false;
}

export function parseSafeInternalReturnDestination(
    value:
        string | null | undefined,
): SafeInternalReturnDestination | null {
    if (
        value === null
        || value === undefined
    ) {
        return null;
    }

    /*
     * Do not trim untrusted navigation input.
     */
    if (
        value.length === 0
        || value[0] !== '/'
    ) {
        return null;
    }

    /*
     * Reject protocol-relative and ambiguous
     * backslash-based navigation.
     */
    if (
        value.startsWith(
            '//',
        )
        || value.startsWith(
            '/\\',
        )
        || value.includes(
            '\\',
        )
    ) {
        return null;
    }

    /*
     * Reject embedded control characters including CR/LF
     * and tab.
     */
    if (
        containsControlCharacter(
            value,
        )
    ) {
        return null;
    }

    return value as SafeInternalReturnDestination;
}
