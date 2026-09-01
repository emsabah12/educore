import type {
    BrowserLoginRequest,
} from '@/platform/auth';

export interface LoginFormValues {
    readonly identifier:
        string;

    readonly password:
        string;
}

export type LoginFormField =
    | 'identifier'
    | 'password';

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

function normalizeIdentifier(
    value: string,
): string {
    /*
     * Frontend normalization is deliberately limited
     * to surrounding UX whitespace.
     *
     * Email/username canonicalization remains a backend
     * authentication responsibility.
     */
    return value.trim();
}

export function validateLoginForm(
    values: LoginFormValues,
): LoginFormValidation {
    const identifier =
        normalizeIdentifier(
            values.identifier,
        );

    const errors:
        LoginFormErrors = {};

    if (
        identifier.length === 0
    ) {
        errors.identifier =
            'Identifier wajib diisi.';
    }

    /*
     * Never trim passwords.
     *
     * Whitespace may legitimately be part of the
     * authentication secret and therefore must reach
     * the backend exactly as entered.
     */
    if (
        values.password.length === 0
    ) {
        errors.password =
            'Password wajib diisi.';
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
            identifier,

            password:
                values.password,
        };

    return {
        ok:
            true,

        request,
    };
}
