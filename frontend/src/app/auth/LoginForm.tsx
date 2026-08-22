import {
    type FormEvent,
    useState,
} from 'react';

import {
    type LoginFormErrors,
    validateLoginForm,
} from '@/app/auth/login-form';
import type {
    BrowserLoginRequest,
} from '@/platform/auth';

export interface LoginFormProps {
    readonly onValidatedSubmit: (
        request: BrowserLoginRequest,
    ) => void | Promise<void>;

    readonly disabled?:
        boolean;

    readonly externalErrors?:
        LoginFormErrors;

    readonly formError?:
        string | null;

    readonly onInputChange?:
        () => void;
}

export function LoginForm({
    onValidatedSubmit,
    disabled = false,
    externalErrors = {},
    formError = null,
    onInputChange,
}: LoginFormProps) {
    const [
        email,
        setEmail,
    ] = useState('');

    const [
        password,
        setPassword,
    ] = useState('');

    const [
        tenantUuid,
        setTenantUuid,
    ] = useState('');

    const [
        localErrors,
        setLocalErrors,
    ] = useState<
        LoginFormErrors
    >({});

    /*
     * Local validation belongs closest to the form and takes
     * precedence over server presentation for the same field.
     */
    const errors:
        LoginFormErrors = {
            ...externalErrors,
            ...localErrors,
        };

    function clearFieldError(
        field:
            keyof LoginFormErrors,
    ): void {
        setLocalErrors(
            (current) => {
                if (
                    current[field]
                        === undefined
                ) {
                    return current;
                }

                const next = {
                    ...current,
                };

                delete next[field];

                return next;
            },
        );
    }

    function notifyInputChange(): void {
        onInputChange?.();
    }

    async function handleSubmit(
        event: FormEvent<HTMLFormElement>,
    ): Promise<void> {
        event.preventDefault();

        if (disabled) {
            return;
        }

        const validation =
            validateLoginForm({
                email,
                password,
                tenantUuid,
            });

        if (! validation.ok) {
            setLocalErrors(
                validation.errors,
            );

            return;
        }

        setLocalErrors({});

        await onValidatedSubmit(
            validation.request,
        );
    }

    return (
        <form
            aria-describedby={
                formError === null
                    ? undefined
                    : 'login-form-error'
            }
            className="space-y-5"
            noValidate
            onSubmit={(event) => {
                void handleSubmit(
                    event,
                );
            }}
        >
            {
                formError !== null
                    ? (
                        <div
                            className="rounded-lg border border-red-900/60 bg-red-950/40 px-4 py-3 text-sm text-red-200"
                            id="login-form-error"
                            role="alert"
                        >
                            {formError}
                        </div>
                    )
                    : null
            }

            <div className="space-y-2">
                <label
                    className="block text-sm font-medium text-slate-200"
                    htmlFor="login-email"
                >
                    Email
                </label>

                <input
                    aria-describedby={
                        errors.email
                            === undefined
                            ? undefined
                            : 'login-email-error'
                    }
                    aria-invalid={
                        errors.email
                            !== undefined
                    }
                    autoCapitalize="none"
                    autoComplete="username"
                    className="w-full rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 text-slate-100 outline-none focus:border-slate-400 disabled:cursor-not-allowed disabled:opacity-60"
                    disabled={disabled}
                    id="login-email"
                    inputMode="email"
                    name="email"
                    onChange={(event) => {
                        setEmail(
                            event.target.value,
                        );

                        clearFieldError(
                            'email',
                        );

                        notifyInputChange();
                    }}
                    spellCheck={false}
                    type="email"
                    value={email}
                />

                {
                    errors.email
                        !== undefined
                        ? (
                            <p
                                className="text-sm text-red-300"
                                id="login-email-error"
                                role="alert"
                            >
                                {errors.email}
                            </p>
                        )
                        : null
                }
            </div>

            <div className="space-y-2">
                <label
                    className="block text-sm font-medium text-slate-200"
                    htmlFor="login-password"
                >
                    Password
                </label>

                <input
                    aria-describedby={
                        errors.password
                            === undefined
                            ? undefined
                            : 'login-password-error'
                    }
                    aria-invalid={
                        errors.password
                            !== undefined
                    }
                    autoComplete="current-password"
                    className="w-full rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 text-slate-100 outline-none focus:border-slate-400 disabled:cursor-not-allowed disabled:opacity-60"
                    disabled={disabled}
                    id="login-password"
                    name="password"
                    onChange={(event) => {
                        setPassword(
                            event.target.value,
                        );

                        clearFieldError(
                            'password',
                        );

                        notifyInputChange();
                    }}
                    type="password"
                    value={password}
                />

                {
                    errors.password
                        !== undefined
                        ? (
                            <p
                                className="text-sm text-red-300"
                                id="login-password-error"
                                role="alert"
                            >
                                {errors.password}
                            </p>
                        )
                        : null
                }
            </div>

            <div className="space-y-2">
                <label
                    className="block text-sm font-medium text-slate-200"
                    htmlFor="login-tenant-uuid"
                >
                    Tenant UUID
                </label>

                <input
                    aria-describedby={
                        errors.tenantUuid
                            === undefined
                            ? 'login-tenant-uuid-help'
                            : 'login-tenant-uuid-help login-tenant-uuid-error'
                    }
                    aria-invalid={
                        errors.tenantUuid
                            !== undefined
                    }
                    autoCapitalize="none"
                    autoComplete="off"
                    className="w-full rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 font-mono text-sm text-slate-100 outline-none focus:border-slate-400 disabled:cursor-not-allowed disabled:opacity-60"
                    disabled={disabled}
                    id="login-tenant-uuid"
                    name="tenantUuid"
                    onChange={(event) => {
                        setTenantUuid(
                            event.target.value,
                        );

                        clearFieldError(
                            'tenantUuid',
                        );

                        notifyInputChange();
                    }}
                    spellCheck={false}
                    type="text"
                    value={tenantUuid}
                />

                <p
                    className="text-sm text-slate-400"
                    id="login-tenant-uuid-help"
                >
                    Masukkan identifier Tenant EduCore yang diberikan kepada Anda.
                </p>

                {
                    errors.tenantUuid
                        !== undefined
                        ? (
                            <p
                                className="text-sm text-red-300"
                                id="login-tenant-uuid-error"
                                role="alert"
                            >
                                {errors.tenantUuid}
                            </p>
                        )
                        : null
                }
            </div>

            <button
                className="w-full rounded-lg bg-slate-100 px-4 py-2.5 font-semibold text-slate-950 transition hover:bg-white disabled:cursor-not-allowed disabled:opacity-60"
                disabled={disabled}
                type="submit"
            >
                Masuk
            </button>
        </form>
    );
}
