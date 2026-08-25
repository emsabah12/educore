import {
    describe,
    expect,
    it,
} from 'vitest';

import {
    applicationNavigationCatalog,
    defineApplicationNavigation,
} from '@/platform/navigation';

describe(
    'Application navigation definition',
    () => {
        it('defines the current registered protected root destination without inventing additional module navigation', () => {
            expect(
                applicationNavigationCatalog,
            ).toEqual([
                {
                    id:
                        'application.home',

                    routeId:
                        'root',

                    label:
                        'Beranda',

                    destination:
                        '/',
                },
            ]);
        });

        it('requires a stable non-empty navigation identity', () => {
            expect(
                () =>
                    defineApplicationNavigation({
                        id:
                            '   ',

                        routeId:
                            'root',

                        label:
                            'Beranda',

                        destination:
                            '/',
                    }),
            ).toThrow(
                'EduCore navigation definition requires a non-empty id.',
            );
        });

        it('requires a stable non-empty route identity', () => {
            expect(
                () =>
                    defineApplicationNavigation({
                        id:
                            'application.home',

                        routeId:
                            '',

                        label:
                            'Beranda',

                        destination:
                            '/',
                    }),
            ).toThrow(
                'EduCore navigation definition requires a non-empty routeId.',
            );
        });

        it('requires a non-empty presentation label', () => {
            expect(
                () =>
                    defineApplicationNavigation({
                        id:
                            'application.home',

                        routeId:
                            'root',

                        label:
                            '   ',

                        destination:
                            '/',
                    }),
            ).toThrow(
                'EduCore navigation definition requires a non-empty label.',
            );
        });

        it('rejects protocol-relative navigation destinations', () => {
            expect(
                () =>
                    defineApplicationNavigation({
                        id:
                            'application.unsafe',

                        routeId:
                            'unsafe',

                        label:
                            'Unsafe',

                        destination:
                            '//evil.example' as `/${string}`,
                    }),
            ).toThrow(
                'EduCore navigation definition requires a safe root-relative destination.',
            );
        });

        it('rejects backslash-based ambiguous navigation destinations', () => {
            expect(
                () =>
                    defineApplicationNavigation({
                        id:
                            'application.unsafe',

                        routeId:
                            'unsafe',

                        label:
                            'Unsafe',

                        destination:
                            '/\\evil.example',
                    }),
            ).toThrow(
                'EduCore navigation definition requires a safe root-relative destination.',
            );
        });

        it('publishes immutable navigation definitions', () => {
            const definition =
                defineApplicationNavigation({
                    id:
                        'application.home',

                    routeId:
                        'root',

                    label:
                        'Beranda',

                    destination:
                        '/',
                });

            expect(
                Object.isFrozen(
                    definition,
                ),
            ).toBe(
                true,
            );

            expect(
                Object.isFrozen(
                    applicationNavigationCatalog,
                ),
            ).toBe(
                true,
            );
        });
    },
);
