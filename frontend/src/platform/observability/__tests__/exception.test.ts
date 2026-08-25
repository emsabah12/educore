import {
    describe,
    expect,
    it,
} from 'vitest';

interface TestSafeObservabilityException {
    readonly kind:
        'error'
        | 'unknown';
}

interface ObservabilityExceptionModule {
    readonly normalizeObservabilityException?:
        (
            error:
                unknown,
        ) => TestSafeObservabilityException;
}

describe(
    'Observability exception normalization',
    () => {
        it('does not retain raw Error diagnostic or custom sensitive properties', async () => {
            const modulePath =
                '../exception';

            const exceptionModule =
                await import(
                    modulePath
                ) as ObservabilityExceptionModule;

            const normalizeException =
                exceptionModule
                    .normalizeObservabilityException;

            expect(
                normalizeException,
            ).toBeTypeOf(
                'function',
            );

            if (
                normalizeException
                    === undefined
            ) {
                return;
            }

            const error =
                new Error(
                    'Sensitive Student secret-id',
                );

            error.stack =
                [
                    'Error: Sensitive Student secret-id',
                    '    at render (https://educore.test/assets/app.js?token=secret-token:10:20)',
                ].join(
                    '\n',
                );

            Object.assign(
                error,
                {
                    authorization:
                        'Bearer secret-token',

                    cookie:
                        'educore_session=secret',

                    studentPayload: {
                        medicalNote:
                            'private-domain-payload',
                    },
                },
            );

            const normalized =
                normalizeException(
                    error,
                );

            expect(
                normalized,
            ).toEqual({
                kind:
                    'error',
            });

            const serialized =
                JSON.stringify(
                    normalized,
                );

            expect(
                serialized,
            ).not.toContain(
                'Sensitive Student',
            );

            expect(
                serialized,
            ).not.toContain(
                'secret-id',
            );

            expect(
                serialized,
            ).not.toContain(
                'secret-token',
            );

            expect(
                serialized,
            ).not.toContain(
                'educore_session',
            );

            expect(
                serialized,
            ).not.toContain(
                'private-domain-payload',
            );

            expect(
                serialized,
            ).not.toContain(
                'app.js',
            );
        });

        it('does not retain arbitrary thrown values', async () => {
            const modulePath =
                '../exception';

            const exceptionModule =
                await import(
                    modulePath
                ) as ObservabilityExceptionModule;

            const normalizeException =
                exceptionModule
                    .normalizeObservabilityException;

            expect(
                normalizeException,
            ).toBeTypeOf(
                'function',
            );

            if (
                normalizeException
                    === undefined
            ) {
                return;
            }

            const normalized =
                normalizeException(
                    'Bearer arbitrary-secret-token',
                );

            expect(
                normalized,
            ).toEqual({
                kind:
                    'unknown',
            });

            expect(
                JSON.stringify(
                    normalized,
                ),
            ).not.toContain(
                'arbitrary-secret-token',
            );
        });
    },
);
