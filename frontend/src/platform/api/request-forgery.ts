const XSRF_COOKIE_NAME = 'XSRF-TOKEN';

const XSRF_HEADER_NAME = 'X-XSRF-TOKEN';

const SAFE_HTTP_METHODS = new Set([
    'GET',
    'HEAD',
    'OPTIONS',
]);

function decodeCookieValue(
    value: string,
): string | null {
    try {
        return decodeURIComponent(value);
    } catch {
        return null;
    }
}

function readCookieValue(
    cookieSource: string,
    cookieName: string,
): string | null {
    const expectedPrefix = `${cookieName}=`;

    for (const cookiePart of cookieSource.split(';')) {
        const normalizedCookie = cookiePart.trim();

        if (! normalizedCookie.startsWith(expectedPrefix)) {
            continue;
        }

        const encodedValue = normalizedCookie.slice(
            expectedPrefix.length,
        );

        if (encodedValue === '') {
            return null;
        }

        return decodeCookieValue(encodedValue);
    }

    return null;
}

export function isRequestForgeryProtectedMethod(
    method: string,
): boolean {
    return ! SAFE_HTTP_METHODS.has(
        method.toUpperCase(),
    );
}

export function readBrowserXsrfToken(): string | null {
    if (typeof document === 'undefined') {
        return null;
    }

    return readCookieValue(
        document.cookie,
        XSRF_COOKIE_NAME,
    );
}

export function applyRequestForgeryHeader(
    headers: Headers,
    method: string,
): void {
    /*
     * BrowserSession transport owns this security header.
     * Callers cannot inject or preserve their own value.
     */
    headers.delete(
        XSRF_HEADER_NAME,
    );

    if (
        ! isRequestForgeryProtectedMethod(
            method,
        )
    ) {
        return;
    }

    const token = readBrowserXsrfToken();

    if (token === null) {
        return;
    }

    headers.set(
        XSRF_HEADER_NAME,
        token,
    );
}
