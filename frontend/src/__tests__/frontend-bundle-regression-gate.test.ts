import {
    describe,
    expect,
    it,
} from 'vitest';

import packageManifest from '../../../package.json';

describe(
    'Frontend bundle regression gate command contract',
    () => {
        it('exposes the canonical production bundle regression verification command', () => {
            const scripts =
                packageManifest
                    .scripts as Readonly<
                        Record<
                            string,
                            string
                        >
                    >;

            expect(
                scripts[
                    'frontend:bundle:check'
                ],
            ).toBe(
                'node scripts/frontend-bundle-regression-check.mjs',
            );
        });
    },
);
