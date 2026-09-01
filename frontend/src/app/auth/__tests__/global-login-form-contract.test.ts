import {
    describe,
    expect,
    it,
} from 'vitest';

import {
    validateLoginForm,
} from '@/app/auth/login-form';

describe(
    'global Browser login form contract',
    () => {
        it('builds identifier and password only for an email-shaped identifier', () => {
            expect(
                validateLoginForm({
                    identifier:
                        '  USER@EXAMPLE.COM  ',

                    password:
                        ' secret password ',
                }),
            ).toEqual({
                ok:
                    true,

                request: {
                    identifier:
                        'USER@EXAMPLE.COM',

                    password:
                        ' secret password ',
                },
            });
        });

        it('accepts a username identifier without requiring email syntax', () => {
            expect(
                validateLoginForm({
                    identifier:
                        '  school.admin  ',

                    password:
                        'secret123',
                }),
            ).toEqual({
                ok:
                    true,

                request: {
                    identifier:
                        'school.admin',

                    password:
                        'secret123',
                },
            });
        });

        it('reports identifier validation without inventing Tenant input', () => {
            expect(
                validateLoginForm({
                    identifier:
                        '   ',

                    password:
                        'secret123',
                }),
            ).toEqual({
                ok:
                    false,

                errors: {
                    identifier:
                        'Identifier wajib diisi.',
                },
            });
        });
    },
);
