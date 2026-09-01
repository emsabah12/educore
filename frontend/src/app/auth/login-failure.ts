import type {
    BrowserApiFailure,
    BrowserApiResponseFailure,
    CanonicalApiErrorBody,
    CanonicalValidationError,
    CanonicalValidationErrors,
} from '@/platform/api';

import type {
    LoginFormErrors,
} from '@/app/auth/login-form';

export type LoginFailurePresentationKind =
    | 'invalid-credentials'
    | 'validation'
    | 'service-unavailable'
    | 'network'
    | 'unexpected';

export interface LoginFailurePresentation {
    readonly kind:
        LoginFailurePresentationKind;

    readonly message:
        string;

    readonly fieldErrors:
        LoginFormErrors;
}

const KNOWN_LOGIN_VALIDATION_FIELDS =
    new Set([
        'identifier',
        'password',
    ]);

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
            ! KNOWN_LOGIN_VALIDATION_FIELDS.has(
                field,
            ),
    );
}

function presentValidationFailure(
    failure: BrowserApiResponseFailure,
): LoginFailurePresentation | null {
    if (
        failure.status !== 422
        || ! isCanonicalValidationError(
            failure.error,
        )
    ) {
        return null;
    }

    const fieldErrors:
        LoginFormErrors = {};

    if (
        hasValidationField(
            failure.error.errors,
            'identifier',
        )
    ) {
        fieldErrors.identifier =
            'Identifier tidak dapat diterima. Periksa kembali email atau username Anda.';
    }

    if (
        hasValidationField(
            failure.error.errors,
            'password',
        )
    ) {
        fieldErrors.password =
            'Password tidak dapat diterima. Periksa kembali password Anda.';
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
                ? 'Data login ditolak oleh server. Periksa kembali data Anda.'
                : 'Periksa kembali data login yang ditandai.',

        fieldErrors,
    };
}

function presentResponseFailure(
    failure: BrowserApiResponseFailure,
): LoginFailurePresentation | null {
    if (
        failure.status === 401
        && failure.error.code
            === 'BROWSER_SESSION_AUTHENTICATION_REQUIRED'
    ) {
        return null;
    }

    if (
        failure.status === 401
        && failure.error.code
            === 'AUTHENTICATION_FAILED'
    ) {
        return {
            kind:
                'invalid-credentials',

            message:
                'Identifier atau password tidak cocok.',

            fieldErrors: {},
        };
    }

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

            message:
                'Layanan sesi EduCore sedang tidak tersedia. Silakan coba lagi.',

            fieldErrors: {},
        };
    }

    return {
        kind:
            'unexpected',

        message:
            'Permintaan masuk tidak dapat diproses. Silakan coba lagi.',

        fieldErrors: {},
    };
}

export function presentLoginFailure(
    failure:
        BrowserApiFailure | null,
): LoginFailurePresentation | null {
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
