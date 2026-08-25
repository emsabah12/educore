import {
    describe,
    expect,
    it,
} from 'vitest';

interface ObservabilityMetadataModule {
    readonly createSafeObservabilityMetadata?:
        (
            input:
                Readonly<Record<string, unknown>>,
        ) => Readonly<Record<string, unknown>>;
}

describe(
    'Observability metadata privacy',
    () => {
        it('retains only explicitly allowlisted operational metadata', async () => {
            const modulePath =
                '../metadata';

            const metadataModule =
                await import(
                    modulePath
                ) as ObservabilityMetadataModule;

            const createSafeMetadata =
                metadataModule
                    .createSafeObservabilityMetadata;

            expect(
                createSafeMetadata,
            ).toBeTypeOf(
                'function',
            );

            if (
                createSafeMetadata
                    === undefined
            ) {
                return;
            }

            const safeMetadata =
                createSafeMetadata({
                    password:
                        'super-secret-password',

                    authorization:
                        'Bearer secret-token',

                    cookie:
                        'educore_session=secret',

                    csrf:
                        'secret-csrf-value',

                    studentName:
                        'Sensitive Student',

                    studentPayload: {
                        medicalNote:
                            'private-domain-payload',
                    },

                    rawUrl:
                        '/students/secret-id?search=private',

                    routeId:
                        'academic.students.view',

                    module:
                        'academic',

                    releaseId:
                        'test-release',

                    environment:
                        'test',
                });

            expect(
                safeMetadata,
            ).toEqual({
                routeId:
                    'academic.students.view',

                module:
                    'academic',

                releaseId:
                    'test-release',

                environment:
                    'test',
            });

            expect(
                JSON.stringify(
                    safeMetadata,
                ),
            ).not.toContain(
                'super-secret-password',
            );

            expect(
                JSON.stringify(
                    safeMetadata,
                ),
            ).not.toContain(
                'secret-token',
            );

            expect(
                JSON.stringify(
                    safeMetadata,
                ),
            ).not.toContain(
                'educore_session',
            );

            expect(
                JSON.stringify(
                    safeMetadata,
                ),
            ).not.toContain(
                'secret-csrf-value',
            );

            expect(
                JSON.stringify(
                    safeMetadata,
                ),
            ).not.toContain(
                'Sensitive Student',
            );

            expect(
                JSON.stringify(
                    safeMetadata,
                ),
            ).not.toContain(
                'private-domain-payload',
            );

            expect(
                JSON.stringify(
                    safeMetadata,
                ),
            ).not.toContain(
                'secret-id',
            );
        });
    },
);
