import type {
    BrowserLoginRequest,
} from '@/platform/auth';

export interface LoginFormValues {
    readonly email:
        string;

    readonly password:
        string;

    readonly tenantUuid:
        string;
}

export type LoginFormField =
    | 'email'
    | 'password'
    | 'tenantUuid';

export type LoginFormErrors =
    Partial<
        Record<
            LoginFormField,
            string
        >
    >;

export interface ValidLoginForm {
    readonly ok:
        true;

    readonly request:
        BrowserLoginRequest;
}

export interface InvalidLoginForm {
    readonly ok:
        false;

    readonly errors:
        LoginFormErrors;
}

export type LoginFormValidation =
    | ValidLoginForm
    | InvalidLoginForm;

const BASIC_EMAIL_PATTERN =
    /^[^\s@]+@[^\s@]+\.[^\s@]+$/u;

/*
 * LoginTokenRequest uses the canonical generic UUID
 * contract rather than requiring one particular UUID
 * version.
 *
 * Client validation therefore checks only canonical
 * hexadecimal UUID structure and does not invent a
 * UUID-version authority that the frontend does not own.
 */
const GENERIC_UUID_PATTERN =
    /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iu;

function normalizeEmail(
    value: string,
): string {
    return value
        .trim()
        .toLowerCase();
}

function normalizeTenantUuid(
    value: string,
): string {
    return value.trim();
}

export function validateLoginForm(
    values: LoginFormValues,
): LoginFormValidation {
    const email =
        normalizeEmail(
            values.email,
        );

    const tenantUuid =
        normalizeTenantUuid(
            values.tenantUuid,
        );

    const errors:
        LoginFormErrors = {};

    if (
        email.length === 0
    ) {
        errors.email =
            'Email wajib diisi.';
    } else if (
        ! BASIC_EMAIL_PATTERN.test(
            email,
        )
    ) {
        errors.email =
            'Format email tidak valid.';
    }

    /*
     * Password is deliberately not trimmed.
     *
     * Authentication credentials must be submitted exactly
     * as entered by the user. Whitespace may legitimately
     * be part of a credential.
     */
    if (
        values.password.length
            === 0
    ) {
        errors.password =
            'Password wajib diisi.';
    }

    if (
        tenantUuid.length
            === 0
    ) {
        errors.tenantUuid =
            'Tenant UUID wajib diisi.';
    } else if (
        ! GENERIC_UUID_PATTERN.test(
            tenantUuid,
        )
    ) {
        errors.tenantUuid =
            'Tenant UUID tidak valid.';
    }

    if (
        Object.keys(
            errors,
        ).length > 0
    ) {
        return {
            ok:
                false,

            errors,
        };
    }

    const request:
        BrowserLoginRequest = {
            email,

            password:
                values.password,

            tenant_uuid:
                tenantUuid,
        };

    return {
        ok:
            true,

        request,
    };
}
