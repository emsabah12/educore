import {
    useState,
} from 'react';

import {
    useBrowserAuthRuntime,
    useBrowserAuthState,
} from '@/app/auth/BrowserAuthProvider';
import {
    LoginForm,
} from '@/app/auth/LoginForm';
import {
    presentLoginFailure,
} from '@/app/auth/login-failure';
import type {
    BrowserLoginRequest,
} from '@/platform/auth';

export function LoginPage() {
    const runtime =
        useBrowserAuthRuntime();

    const authentication =
        useBrowserAuthState();

    const [
        failureDismissed,
        setFailureDismissed,
    ] = useState(
        false,
    );

    const formDisabled =
        authentication.status
            !== 'anonymous';

    const failurePresentation =
        authentication.status
            === 'anonymous'
        && ! failureDismissed
            ? presentLoginFailure(
                authentication.failure,
            )
            : null;

    function handleFormInputChange(): void {
        /*
         * Auth runtime keeps the authoritative technical
         * failure. The application only dismisses the current
         * user-facing presentation once the user begins
         * correcting input.
         */
        if (
            authentication.status
                === 'anonymous'
            && authentication.failure
                !== null
        ) {
            setFailureDismissed(
                true,
            );
        }
    }

    async function handleValidatedSubmit(
        request: BrowserLoginRequest,
    ): Promise<void> {
        /*
         * The subscribed React snapshot controls the visible
         * form state, while the live runtime state is checked
         * again immediately before dispatching authentication.
         *
         * This prevents a stale render or rapid duplicate
         * submit from attempting an invalid LOGIN_STARTED
         * transition after another login already began.
         */
        if (
            runtime.getState()
                .status
                !== 'anonymous'
        ) {
            return;
        }

        /*
         * A new login attempt owns a fresh failure lifecycle.
         * Any previously dismissed presentation must therefore
         * be eligible to appear again if this attempt fails.
         */
        setFailureDismissed(
            false,
        );

        /*
         * BrowserAuthRuntime owns:
         * - CSRF bootstrap
         * - browser login transport
         * - canonical context resolution
         *
         * LoginPage deliberately does not inspect the returned
         * state or navigate. LoginRouteBoundary reacts to the
         * authoritative authentication state instead.
         */
        await runtime.login(
            request,
        );
    }

    return (
        <main className="min-h-screen bg-slate-950 text-slate-100">
            <section className="mx-auto flex min-h-screen max-w-lg items-center px-6 py-16">
                <div className="w-full space-y-6">
                    <div className="space-y-3">
                        <p className="text-sm font-semibold uppercase tracking-[0.2em] text-slate-400">
                            EduCore
                        </p>

                        <h1 className="text-3xl font-semibold tracking-tight">
                            Masuk ke EduCore
                        </h1>

                        <p className="leading-7 text-slate-300">
                            Gunakan email atau username akun EduCore Anda
                            untuk memulai Browser Session yang aman.
                        </p>
                    </div>

                    <LoginForm
                        disabled={
                            formDisabled
                        }
                        externalErrors={
                            failurePresentation
                                ?.fieldErrors
                            ?? {}
                        }
                        formError={
                            failurePresentation
                                ?.message
                            ?? null
                        }
                        onInputChange={
                            handleFormInputChange
                        }
                        onValidatedSubmit={
                            handleValidatedSubmit
                        }
                    />
                </div>
            </section>
        </main>
    );
}
