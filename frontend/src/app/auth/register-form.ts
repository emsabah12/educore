import type {
    TenantRegistrationRequest,
} from '@/platform/auth';

export interface RegisterFormValues {
    readonly name:
        string;

    readonly subdomain:
        string;

    readonly adminName:
        string;

    readonly adminEmail:
        string;

    readonly adminPassword:
        string;
}

export type RegisterFormField =
    | 'name'
    | 'subdomain'
    | 'adminName'
    | 'adminEmail'
    | 'adminPassword';

export type RegisterFormErrors =
    Partial<
        Record<
            RegisterFormField,
            string
        >
    >;

export interface ValidRegisterForm {
    readonly ok:
        true;

    readonly request:
        TenantRegistrationRequest;
}

export interface InvalidRegisterForm {
    readonly ok:
        false;

    readonly errors:
        RegisterFormErrors;
}

export type RegisterFormValidation =
    | ValidRegisterForm
    | InvalidRegisterForm;

/*
 * §Sengaja SAMA PERSIS dengan pola regex di
 * RegisterTenantRequest (backend, Laravel FormRequest) --
 * mengecek format lebih awal di sisi klien adalah kemudahan
 * UX semata. Keputusan APAKAH subdomain itu benar-benar
 * tersedia (unik) tetap sepenuhnya wewenang backend.
 */
const SUBDOMAIN_PATTERN =
    /^[a-z0-9](?:[a-z0-9-]{0,48}[a-z0-9])?$/;

function normalizeTrimmed(
    value: string,
): string {
    return value.trim();
}

function normalizeSubdomain(
    value: string,
): string {
    return value
        .trim()
        .toLowerCase();
}

function normalizeEmail(
    value: string,
): string {
    return value
        .trim()
        .toLowerCase();
}

export function validateRegisterForm(
    values: RegisterFormValues,
): RegisterFormValidation {
    const name =
        normalizeTrimmed(
            values.name,
        );

    const subdomain =
        normalizeSubdomain(
            values.subdomain,
        );

    const adminName =
        normalizeTrimmed(
            values.adminName,
        );

    const adminEmail =
        normalizeEmail(
            values.adminEmail,
        );

    const errors:
        RegisterFormErrors = {};

    if (name.length < 3) {
        errors.name =
            'Nama sekolah/institusi wajib diisi, minimal 3 karakter.';
    }

    if (subdomain.length === 0) {
        errors.subdomain =
            'Subdomain wajib diisi.';
    } else if (
        ! SUBDOMAIN_PATTERN.test(
            subdomain,
        )
    ) {
        errors.subdomain =
            'Subdomain hanya boleh berisi huruf kecil, angka, dan tanda hubung.';
    }

    if (adminName.length < 3) {
        errors.adminName =
            'Nama Anda wajib diisi, minimal 3 karakter.';
    }

    if (adminEmail.length === 0) {
        errors.adminEmail =
            'Email wajib diisi.';
    } else if (
        ! adminEmail.includes(
            '@',
        )
    ) {
        errors.adminEmail =
            'Email tidak valid.';
    }

    /*
     * Never trim passwords.
     *
     * Whitespace may legitimately be part of the
     * authentication secret and therefore must reach
     * the backend exactly as entered.
     */
    if (
        values.adminPassword.length < 8
    ) {
        errors.adminPassword =
            'Password wajib diisi, minimal 8 karakter.';
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
        TenantRegistrationRequest = {
            name,

            subdomain,

            admin_name:
                adminName,

            admin_email:
                adminEmail,

            admin_password:
                values.adminPassword,
        };

    return {
        ok:
            true,

        request,
    };
}
