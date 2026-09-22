import {
    Navigate,
    useLocation,
} from 'react-router';

import {
    useBrowserAuthState,
} from '@/app/auth/BrowserAuthProvider';
import {
    RegisterPage,
} from '@/app/RegisterPage';
import {
    resolvePostLoginDestination,
} from '@/platform/routing';

/*
 * §Struktur file ini SENGAJA identik dengan LoginRouteBoundary
 * (duplikasi kecil yang disengaja) -- registrasi yang berhasil
 * menghasilkan status authentication yang PERSIS SAMA dengan
 * login berhasil (lihat BrowserAuthRuntime.register()), jadi
 * logika redirect-nya juga harus persis sama. Duplikasi switch
 * pendek ini dipilih ketimbang menggeneralisasi
 * LoginRouteBoundary yang sudah stabil dan teruji, supaya
 * risiko regresi pada jalur login tetap nol.
 */
export function RegisterRouteBoundary() {
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
            return (
                <RegisterPage />
            );
    }
}
