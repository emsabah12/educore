import {
    type FormEvent,
    useState,
} from 'react';

import {
    type RegisterFormErrors,
    validateRegisterForm,
} from '@/app/auth/register-form';
import type {
    TenantRegistrationRequest,
} from '@/platform/auth';

export interface RegisterFormProps {
    readonly onValidatedSubmit: (
        request: TenantRegistrationRequest,
    ) => void | Promise<void>;

    readonly disabled?:
        boolean;

    readonly externalErrors?:
        RegisterFormErrors;

    readonly formError?:
        string | null;

    readonly onInputChange?:
        () => void;
}

export function RegisterForm({
    onValidatedSubmit,
    disabled = false,
    externalErrors = {},
    formError = null,
    onInputChange,
}: RegisterFormProps) {
    const [
        name,
        setName,
    ] = useState('');

    const [
        subdomain,
        setSubdomain,
    ] = useState('');

    const [
        adminName,
        setAdminName,
    ] = useState('');

    const [
        adminEmail,
        setAdminEmail,
    ] = useState('');

    const [
        adminPassword,
        setAdminPassword,
    ] = useState('');

    const [
        localErrors,
        setLocalErrors,
    ] = useState<
        RegisterFormErrors
    >({});

    const errors:
        RegisterFormErrors = {
            ...externalErrors,
            ...localErrors,
        };

    function clearFieldError(
        field:
            keyof RegisterFormErrors,
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
            validateRegisterForm({
                name,
                subdomain,
                adminName,
                adminEmail,
                adminPassword,
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
                    : 'register-form-error'
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
                            id="register-form-error"
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
                    htmlFor="register-name"
                >
                    Nama sekolah/institusi
                </label>

                <input
                    aria-describedby={
                        errors.name
                            === undefined
                            ? undefined
                            : 'register-name-error'
                    }
                    aria-invalid={
                        errors.name
                            !== undefined
                    }
                    autoComplete="organization"
                    className="w-full rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 text-slate-100 outline-none focus:border-slate-400 disabled:cursor-not-allowed disabled:opacity-60"
                    disabled={disabled}
                    id="register-name"
                    name="name"
                    onChange={(event) => {
                        setName(
                            event.target.value,
                        );

                        clearFieldError(
                            'name',
                        );

                        notifyInputChange();
                    }}
                    type="text"
                    value={name}
                />

                {
                    errors.name
                        !== undefined
                        ? (
                            <p
                                className="text-sm text-red-300"
                                id="register-name-error"
                                role="alert"
                            >
                                {errors.name}
                            </p>
                        )
                        : null
                }
            </div>

            <div className="space-y-2">
                <label
                    className="block text-sm font-medium text-slate-200"
                    htmlFor="register-subdomain"
                >
                    Subdomain
                </label>

                <input
                    aria-describedby={
                        errors.subdomain
                            === undefined
                            ? 'register-subdomain-help'
                            : 'register-subdomain-help register-subdomain-error'
                    }
                    aria-invalid={
                        errors.subdomain
                            !== undefined
                    }
                    autoCapitalize="none"
                    className="w-full rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 text-slate-100 outline-none focus:border-slate-400 disabled:cursor-not-allowed disabled:opacity-60"
                    disabled={disabled}
                    id="register-subdomain"
                    name="subdomain"
                    onChange={(event) => {
                        setSubdomain(
                            event.target.value,
                        );

                        clearFieldError(
                            'subdomain',
                        );

                        notifyInputChange();
                    }}
                    spellCheck={false}
                    type="text"
                    value={subdomain}
                />

                <p
                    className="text-sm text-slate-400"
                    id="register-subdomain-help"
                >
                    Huruf kecil, angka, dan tanda hubung saja. Ini akan
                    menjadi alamat unik sekolah Anda.
                </p>

                {
                    errors.subdomain
                        !== undefined
                        ? (
                            <p
                                className="text-sm text-red-300"
                                id="register-subdomain-error"
                                role="alert"
                            >
                                {errors.subdomain}
                            </p>
                        )
                        : null
                }
            </div>

            <div className="space-y-2">
                <label
                    className="block text-sm font-medium text-slate-200"
                    htmlFor="register-admin-name"
                >
                    Nama Anda
                </label>

                <input
                    aria-describedby={
                        errors.adminName
                            === undefined
                            ? undefined
                            : 'register-admin-name-error'
                    }
                    aria-invalid={
                        errors.adminName
                            !== undefined
                    }
                    autoComplete="name"
                    className="w-full rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 text-slate-100 outline-none focus:border-slate-400 disabled:cursor-not-allowed disabled:opacity-60"
                    disabled={disabled}
                    id="register-admin-name"
                    name="adminName"
                    onChange={(event) => {
                        setAdminName(
                            event.target.value,
                        );

                        clearFieldError(
                            'adminName',
                        );

                        notifyInputChange();
                    }}
                    type="text"
                    value={adminName}
                />

                {
                    errors.adminName
                        !== undefined
                        ? (
                            <p
                                className="text-sm text-red-300"
                                id="register-admin-name-error"
                                role="alert"
                            >
                                {errors.adminName}
                            </p>
                        )
                        : null
                }
            </div>

            <div className="space-y-2">
                <label
                    className="block text-sm font-medium text-slate-200"
                    htmlFor="register-admin-email"
                >
                    Email Anda
                </label>

                <input
                    aria-describedby={
                        errors.adminEmail
                            === undefined
                            ? undefined
                            : 'register-admin-email-error'
                    }
                    aria-invalid={
                        errors.adminEmail
                            !== undefined
                    }
                    autoCapitalize="none"
                    autoComplete="email"
                    className="w-full rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 text-slate-100 outline-none focus:border-slate-400 disabled:cursor-not-allowed disabled:opacity-60"
                    disabled={disabled}
                    id="register-admin-email"
                    name="adminEmail"
                    onChange={(event) => {
                        setAdminEmail(
                            event.target.value,
                        );

                        clearFieldError(
                            'adminEmail',
                        );

                        notifyInputChange();
                    }}
                    spellCheck={false}
                    type="email"
                    value={adminEmail}
                />

                {
                    errors.adminEmail
                        !== undefined
                        ? (
                            <p
                                className="text-sm text-red-300"
                                id="register-admin-email-error"
                                role="alert"
                            >
                                {errors.adminEmail}
                            </p>
                        )
                        : null
                }
            </div>

            <div className="space-y-2">
                <label
                    className="block text-sm font-medium text-slate-200"
                    htmlFor="register-admin-password"
                >
                    Password
                </label>

                <input
                    aria-describedby={
                        errors.adminPassword
                            === undefined
                            ? 'register-admin-password-help'
                            : 'register-admin-password-help register-admin-password-error'
                    }
                    aria-invalid={
                        errors.adminPassword
                            !== undefined
                    }
                    autoComplete="new-password"
                    className="w-full rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 text-slate-100 outline-none focus:border-slate-400 disabled:cursor-not-allowed disabled:opacity-60"
                    disabled={disabled}
                    id="register-admin-password"
                    name="adminPassword"
                    onChange={(event) => {
                        setAdminPassword(
                            event.target.value,
                        );

                        clearFieldError(
                            'adminPassword',
                        );

                        notifyInputChange();
                    }}
                    type="password"
                    value={adminPassword}
                />

                <p
                    className="text-sm text-slate-400"
                    id="register-admin-password-help"
                >
                    Minimal 8 karakter.
                </p>

                {
                    errors.adminPassword
                        !== undefined
                        ? (
                            <p
                                className="text-sm text-red-300"
                                id="register-admin-password-error"
                                role="alert"
                            >
                                {errors.adminPassword}
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
                Daftar
            </button>
        </form>
    );
}
