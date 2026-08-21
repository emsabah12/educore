import {
    describe,
    expect,
    it,
} from 'vitest';

import {
    parseSafeInternalReturnDestination,
} from '@/platform/routing';

describe(
    'Safe internal return destination',
    () => {
        it('accepts root-relative application locations', () => {
            expect(
                parseSafeInternalReturnDestination(
                    '/',
                ),
            ).toBe(
                '/',
            );

            expect(
                parseSafeInternalReturnDestination(
                    '/dashboard',
                ),
            ).toBe(
                '/dashboard',
            );

            expect(
                parseSafeInternalReturnDestination(
                    '/academic/students?page=2#results',
                ),
            ).toBe(
                '/academic/students?page=2#results',
            );
        });

        it('rejects empty or absent destinations', () => {
            expect(
                parseSafeInternalReturnDestination(
                    null,
                ),
            ).toBeNull();

            expect(
                parseSafeInternalReturnDestination(
                    undefined,
                ),
            ).toBeNull();

            expect(
                parseSafeInternalReturnDestination(
                    '',
                ),
            ).toBeNull();
        });

        it('rejects absolute external URLs', () => {
            expect(
                parseSafeInternalReturnDestination(
                    'https://evil.example/path',
                ),
            ).toBeNull();

            expect(
                parseSafeInternalReturnDestination(
                    'http://evil.example/path',
                ),
            ).toBeNull();
        });

        it('rejects protocol-relative URLs', () => {
            expect(
                parseSafeInternalReturnDestination(
                    '//evil.example/path',
                ),
            ).toBeNull();
        });

        it('rejects backslash-based ambiguous navigation targets', () => {
            expect(
                parseSafeInternalReturnDestination(
                    '/\\evil.example/path',
                ),
            ).toBeNull();

            expect(
                parseSafeInternalReturnDestination(
                    '/safe\\unsafe',
                ),
            ).toBeNull();
        });

        it('rejects URI schemes instead of interpreting them as application locations', () => {
            expect(
                parseSafeInternalReturnDestination(
                    'javascript:alert(1)',
                ),
            ).toBeNull();

            expect(
                parseSafeInternalReturnDestination(
                    'data:text/html,test',
                ),
            ).toBeNull();
        });

        it('does not silently trim malformed destinations', () => {
            expect(
                parseSafeInternalReturnDestination(
                    ' /dashboard',
                ),
            ).toBeNull();

            expect(
                parseSafeInternalReturnDestination(
                    '/dashboard ',
                ),
            ).toBe(
                '/dashboard ',
            );
        });

        it('rejects embedded control characters', () => {
            expect(
                parseSafeInternalReturnDestination(
                    '/dashboard\nnext',
                ),
            ).toBeNull();

            expect(
                parseSafeInternalReturnDestination(
                    '/dashboard\rnext',
                ),
            ).toBeNull();

            expect(
                parseSafeInternalReturnDestination(
                    '/dashboard\tnext',
                ),
            ).toBeNull();
        });
    },
);
