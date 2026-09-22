import {
    describe,
    expect,
    it,
} from 'vitest';

import {
    validateRegisterForm,
} from '@/app/auth/register-form';

const validValues = {
    name:
        'SMA Negeri Uji Coba',

    subdomain:
        'sma-uji-coba',

    adminName:
        'Kepala Sekolah Baru',

    adminEmail:
        'admin-baru@example.com',

    adminPassword:
        'correct horse battery staple',
};

describe(
    'Register form input validation',
    () => {
        it('creates the canonical Tenant self-registration request', () => {
            expect(
                validateRegisterForm(
                    validValues,
                ),
            ).toEqual({
                ok:
                    true,

                request: {
                    name:
                        'SMA Negeri Uji Coba',

                    subdomain:
                        'sma-uji-coba',

                    admin_name:
                        'Kepala Sekolah Baru',

                    admin_email:
                        'admin-baru@example.com',

                    admin_password:
                        'correct horse battery staple',
                },
            });
        });

        it('trims surrounding whitespace and normalizes subdomain/email case without modifying password', () => {
            expect(
                validateRegisterForm({
                    name:
                        '  SMA Negeri Uji Coba  ',

                    subdomain:
                        '  SMA-Uji-Coba  ',

                    adminName:
                        '  Kepala Sekolah Baru  ',

                    adminEmail:
                        '  ADMIN-BARU@EXAMPLE.COM  ',

                    adminPassword:
                        '  secret value  ',
                }),
            ).toEqual({
                ok:
                    true,

                request: {
                    name:
                        'SMA Negeri Uji Coba',

                    subdomain:
                        'sma-uji-coba',

                    admin_name:
                        'Kepala Sekolah Baru',

                    admin_email:
                        'admin-baru@example.com',

                    admin_password:
                        '  secret value  ',
                },
            });
        });

        it('requires a school/institution name of at least 3 characters', () => {
            expect(
                validateRegisterForm({
                    ...validValues,

                    name:
                        'SM',
                }),
            ).toEqual({
                ok:
                    false,

                errors: {
                    name:
                        'Nama sekolah/institusi wajib diisi, minimal 3 karakter.',
                },
            });
        });

        it('requires a subdomain', () => {
            expect(
                validateRegisterForm({
                    ...validValues,

                    subdomain:
                        '   ',
                }),
            ).toEqual({
                ok:
                    false,

                errors: {
                    subdomain:
                        'Subdomain wajib diisi.',
                },
            });
        });

        it('rejects a subdomain with characters outside lowercase letters, numbers, and hyphens', () => {
            expect(
                validateRegisterForm({
                    ...validValues,

                    subdomain:
                        'Subdomain Dengan Spasi!',
                }),
            ).toEqual({
                ok:
                    false,

                errors: {
                    subdomain:
                        'Subdomain hanya boleh berisi huruf kecil, angka, dan tanda hubung.',
                },
            });
        });

        it('requires an admin name of at least 3 characters', () => {
            expect(
                validateRegisterForm({
                    ...validValues,

                    adminName:
                        'A',
                }),
            ).toEqual({
                ok:
                    false,

                errors: {
                    adminName:
                        'Nama Anda wajib diisi, minimal 3 karakter.',
                },
            });
        });

        it('requires an admin email containing @', () => {
            expect(
                validateRegisterForm({
                    ...validValues,

                    adminEmail:
                        'not-an-email',
                }),
            ).toEqual({
                ok:
                    false,

                errors: {
                    adminEmail:
                        'Email tidak valid.',
                },
            });
        });

        it('requires an admin password of at least 8 characters without trimming credential input', () => {
            expect(
                validateRegisterForm({
                    ...validValues,

                    adminPassword:
                        'short',
                }),
            ).toEqual({
                ok:
                    false,

                errors: {
                    adminPassword:
                        'Password wajib diisi, minimal 8 karakter.',
                },
            });

            expect(
                validateRegisterForm({
                    ...validValues,

                    adminPassword:
                        '        ',
                }),
            ).toEqual({
                ok:
                    true,

                request: {
                    name:
                        validValues.name,

                    subdomain:
                        validValues.subdomain,

                    admin_name:
                        validValues.adminName,

                    admin_email:
                        validValues.adminEmail,

                    admin_password:
                        '        ',
                },
            });
        });

        it('returns independently detectable field errors together', () => {
            expect(
                validateRegisterForm({
                    name:
                        '',

                    subdomain:
                        '',

                    adminName:
                        '',

                    adminEmail:
                        '',

                    adminPassword:
                        '',
                }),
            ).toEqual({
                ok:
                    false,

                errors: {
                    name:
                        'Nama sekolah/institusi wajib diisi, minimal 3 karakter.',

                    subdomain:
                        'Subdomain wajib diisi.',

                    adminName:
                        'Nama Anda wajib diisi, minimal 3 karakter.',

                    adminEmail:
                        'Email wajib diisi.',

                    adminPassword:
                        'Password wajib diisi, minimal 8 karakter.',
                },
            });
        });
    },
);
