import {
    useState,
} from 'react';
import {
    Link,
} from 'react-router';

import {
    useBrowserAuthRuntime,
    useBrowserAuthState,
} from '@/app/auth/BrowserAuthProvider';
import {
    RegisterForm,
} from '@/app/auth/RegisterForm';
import {
    presentRegisterFailure,
} from '@/app/auth/register-failure';
import type {
    TenantRegistrationRequest,
} from '@/platform/auth';

export function RegisterPage() {
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
            ? presentRegisterFailure(
                authentication.failure,
            )
            : null;

    function handleFormInputChange(): void {
        /*
         * Sama persis dengan LoginPage -- auth runtime tetap
         * menyimpan kegagalan teknis otoritatif, aplikasi cuma
         * membatalkan presentasi yang sedang tampil begitu
         * pengguna mulai memperbaiki input.
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
        request: TenantRegistrationRequest,
    ): Promise<void> {
        /*
         * Sama persis dengan LoginPage: state runtime hidup
         * dicek ulang tepat sebelum dispatch, supaya render
         * basi atau submit ganda cepat tidak mencoba transisi
         * LOGIN_STARTED yang tidak valid setelah percobaan
         * registrasi lain sudah dimulai.
         */
        if (
            runtime.getState()
                .status
                !== 'anonymous'
        ) {
            return;
        }

        /*
         * Percobaan registrasi baru memiliki siklus kegagalan
         * sendiri. Presentasi yang sebelumnya ditutup harus bisa
         * tampil lagi kalau percobaan ini juga gagal.
         */
        setFailureDismissed(
            false,
        );

        /*
         * BrowserAuthRuntime.register() memiliki:
         * - CSRF bootstrap
         * - transport pendaftaran tenant mandiri
         * - transisi state yang PERSIS SAMA dengan login berhasil
         *
         * RegisterPage sengaja TIDAK memeriksa state yang
         * dikembalikan atau bernavigasi sendiri -- boundary route
         * yang bereaksi terhadap authentication state otoritatif
         * (sama seperti LoginRouteBoundary bereaksi setelah login).
         */
        await runtime.register(
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
                            Daftarkan sekolah Anda
                        </h1>

                        <p className="leading-7 text-slate-300">
                            Buat ruang kerja EduCore baru untuk sekolah
                            atau institusi Anda. Anda akan langsung
                            menjadi admin dan bisa langsung mulai
                            memakainya.
                        </p>
                    </div>

                    <RegisterForm
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

                    <p className="text-center text-sm text-slate-400">
                        Sudah punya akun?{' '}
                        <Link
                            className="font-medium text-slate-100 underline underline-offset-4 hover:text-white"
                            to="/login"
                        >
                            Masuk
                        </Link>
                    </p>
                </div>
            </section>
        </main>
    );
}
