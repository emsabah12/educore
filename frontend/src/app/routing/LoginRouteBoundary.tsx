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
        case 'identity-authenticated':
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
        case 'logging-out':
        case 'unavailable':
            /*
             * Do not redirect while authentication truth is
             * unresolved, transitioning, unavailable, or
             * authoritatively anonymous.
             *
             * Once global Identity authentication succeeds,
             * Membership/Tenant orchestration belongs to the
             * application route lifecycle rather than the
             * credential-entry route.
             */
            return (
                <LoginPage />
            );
    }
}
