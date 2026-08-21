import {
    describe,
    expect,
    it,
} from 'vitest';

import {
    resolvePostLoginDestination,
} from '@/platform/routing';

describe(
    'Post-login destination resolution',
    () => {
        it('preserves a validated internal application destination', () => {
            expect(
                resolvePostLoginDestination(
                    '/academic/students?page=2#results',
                ),
            ).toBe(
                '/academic/students?page=2#results',
            );
        });

        it('falls back to the canonical application entry when no return destination exists', () => {
            expect(
                resolvePostLoginDestination(
                    null,
                ),
            ).toBe(
                '/',
            );

            expect(
                resolvePostLoginDestination(
                    undefined,
                ),
            ).toBe(
                '/',
            );

            expect(
                resolvePostLoginDestination(
                    '',
                ),
            ).toBe(
                '/',
            );
        });

        it('falls back when an absolute external destination is supplied', () => {
            expect(
                resolvePostLoginDestination(
                    'https://evil.example/path',
                ),
            ).toBe(
                '/',
            );
        });

        it('falls back when a protocol-relative destination is supplied', () => {
            expect(
                resolvePostLoginDestination(
                    '//evil.example/path',
                ),
            ).toBe(
                '/',
            );
        });

        it('falls back when ambiguous backslash navigation is supplied', () => {
            expect(
                resolvePostLoginDestination(
                    '/\\evil.example/path',
                ),
            ).toBe(
                '/',
            );
        });

        it('rejects the login route as its own post-login destination', () => {
            expect(
                resolvePostLoginDestination(
                    '/login',
                ),
            ).toBe(
                '/',
            );

            expect(
                resolvePostLoginDestination(
                    '/login/',
                ),
            ).toBe(
                '/',
            );
        });

        it('rejects login destinations carrying query or hash state', () => {
            expect(
                resolvePostLoginDestination(
                    '/login?returnTo=%2Facademic',
                ),
            ).toBe(
                '/',
            );

            expect(
                resolvePostLoginDestination(
                    '/login#form',
                ),
            ).toBe(
                '/',
            );
        });

        it('does not confuse similarly-prefixed application routes with the login route', () => {
            expect(
                resolvePostLoginDestination(
                    '/login-history',
                ),
            ).toBe(
                '/login-history',
            );

            expect(
                resolvePostLoginDestination(
                    '/login/help',
                ),
            ).toBe(
                '/login/help',
            );
        });
    },
);
