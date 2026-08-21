import {
    Navigate,
    useLocation,
} from 'react-router';

import {
    useBrowserAuthState,
} from '@/app/auth/BrowserAuthProvider';
import {
    LoginPage,
} from '@/app/LoginPage';
import {
    resolvePostLoginDestination,
} from '@/platform/routing';

export function LoginRouteBoundary() {
    const authentication =
        useBrowserAuthState();

    const location =
        useLocation();

    switch (
        authentication.status
    ) {
        case 'authenticated':
        case 'membership-context-required': {
            /*
             * returnTo is navigation convenience only.
             *
             * URLSearchParams decodes the query value, then
             * the canonical post-login resolver validates
             * the internal destination and rejects login
             * self-targets before navigation.
             */
            const parameters =
                new URLSearchParams(
                    location.search,
                );

            const destination =
                resolvePostLoginDestination(
                    parameters.get(
                        'returnTo',
                    ),
                );

            return (
                <Navigate
                    replace
                    to={destination}
                />
            );
        }

        case 'unknown':
        case 'anonymous':
        case 'authenticating':
        case 'resolving-context':
        case 'logging-out':
        case 'unavailable':
            /*
             * Do not redirect while authentication truth is
             * unresolved, transitioning, unavailable, or
             * authoritatively anonymous.
             *
             * The actual login form/runtime integration is
             * intentionally outside this routing step.
             */
            return (
                <LoginPage />
            );
    }
}
