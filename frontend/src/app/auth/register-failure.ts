import type {
    BrowserApiFailure,
    BrowserApiResponseFailure,
    CanonicalApiErrorBody,
    CanonicalValidationError,
    CanonicalValidationErrors,
} from '@/platform/api';

import type {
    RegisterFormErrors,
    RegisterFormField,
} from '@/app/auth/register-form';

export type RegisterFailurePresentationKind =
    | 'validation'
    | 'service-unavailable'
    | 'network'
    | 'unexpected';

export interface RegisterFailurePresentation {
    readonly kind:
        RegisterFailurePresentationKind;

    readonly message:
        string;

    readonly fieldErrors:
        RegisterFormErrors;
}

/*
 * §Backend field name (RegisterTenantRequest) -> frontend
 * form field name (RegisterFormValues). Sengaja eksplisit,
 * bukan otomatis snake_case -> camelCase, supaya perubahan
 * satu sisi tidak diam-diam merusak sisi lain.
 */
const VALIDATION_FIELD_MAP = {
    name:
        'name',

    subdomain:
        'subdomain',

    admin_name:
        'adminName',

    admin_email:
        'adminEmail',

    admin_password:
        'adminPassword',
} satisfies Record<
    string,
    RegisterFormField
>;

const FIELD_ERROR_MESSAGES: Record<
    keyof typeof VALIDATION_FIELD_MAP,
    string
> = {
    name:
        'Nama sekolah/institusi tidak dapat diterima.',

    subdomain:
        'Subdomain tidak dapat diterima. Kemungkinan sudah dipakai institusi lain.',

    admin_name:
        'Nama Anda tidak dapat diterima.',

    admin_email:
        'Email tidak dapat diterima. Kemungkinan sudah terdaftar.',

    admin_password:
        'Password tidak dapat diterima.',
};

function isCanonicalValidationError(
    error: CanonicalApiErrorBody,
): error is CanonicalValidationError {
    return (
        error.code === 'VALIDATION_FAILED'
        && 'errors' in error
    );
}

function hasValidationField(
    errors: CanonicalValidationErrors,
    field: string,
): boolean {
    return Object.prototype.hasOwnProperty.call(
        errors,
        field,
    );
}

function hasUnknownValidationField(
    errors: CanonicalValidationErrors,
): boolean {
    return Object.keys(
        errors,
    ).some(
        (field) =>
            ! Object.prototype.hasOwnProperty.call(
                VALIDATION_FIELD_MAP,
                field,
            ),
    );
}

function presentValidationFailure(
    failure: BrowserApiResponseFailure,
): RegisterFailurePresentation | null {
    if (
        failure.status !== 422
        || ! isCanonicalValidationError(
            failure.error,
        )
    ) {
        return null;
    }

    const fieldErrors:
        RegisterFormErrors = {};

    for (
        const [
            backendField,
            formField,
        ] of Object.entries(
            VALIDATION_FIELD_MAP,
        )
    ) {
        if (
            hasValidationField(
                failure.error.errors,
                backendField,
            )
        ) {
            fieldErrors[formField] =
                FIELD_ERROR_MESSAGES[
                    backendField as keyof typeof FIELD_ERROR_MESSAGES
                ];
        }
    }

    const unknownFieldPresent =
        hasUnknownValidationField(
            failure.error.errors,
        );

    return {
        kind:
            'validation',

        message:
            unknownFieldPresent
                ? 'Data pendaftaran ditolak oleh server. Periksa kembali data Anda.'
                : 'Periksa kembali data yang ditandai.',

        fieldErrors,
    };
}

function presentResponseFailure(
    failure: BrowserApiResponseFailure,
): RegisterFailurePresentation | null {
    const validation =
        presentValidationFailure(
            failure,
        );

    if (validation !== null) {
        return validation;
    }

    if (
        failure.status === 503
        && failure.error.code
            === 'BROWSER_SESSION_UNAVAILABLE'
    ) {
        return {
            kind:
                'service-unavailable',

            /*
             * §Kasus khusus BROWSER_SESSION_UNAVAILABLE untuk
             * registrasi -- lihat catatan di
             * TenantSelfRegistrationController: tenant SUDAH
             * berhasil terbentuk, cuma sesi browser yang gagal
             * dibangun. Pesan ini SENGAJA beda dari login biasa,
             * supaya pengguna tidak mengira pendaftarannya gagal
             * dan mencoba daftar ulang dengan subdomain yang sama.
             */
            message:
                'Sekolah Anda berhasil didaftarkan, tapi kami tidak dapat langsung memasukkan Anda. Silakan masuk secara manual.',

            fieldErrors: {},
        };
    }

    return {
        kind:
            'unexpected',

        message:
            'Permintaan pendaftaran tidak dapat diproses. Silakan coba lagi.',

        fieldErrors: {},
    };
}

export function presentRegisterFailure(
    failure:
        BrowserApiFailure | null,
): RegisterFailurePresentation | null {
    if (failure === null) {
        return null;
    }

    switch (failure.kind) {
        case 'aborted':
            return null;

        case 'network':
            return {
                kind:
                    'network',

                message:
                    'Tidak dapat terhubung ke EduCore. Periksa koneksi Anda lalu coba lagi.',

                fieldErrors: {},
            };

        case 'protocol':
            return {
                kind:
                    'unexpected',

                message:
                    'EduCore menerima respons yang tidak dapat diproses. Silakan coba lagi.',

                fieldErrors: {},
            };

        case 'response':
            return presentResponseFailure(
                failure,
            );
    }
}
