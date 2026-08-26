import {
    describe,
    expect,
    it,
} from 'vitest';

import packageManifest from '../../../package.json';

describe(
    'Frontend CI enforcement gate command contract',
    () => {
        it('exposes the canonical static CI workflow verification command', () => {
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
                    'frontend:ci:check'
                ],
            ).toBe(
                'node scripts/frontend-ci-enforcement-check.mjs',
            );
        });
    },
);
