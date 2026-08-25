import {
    describe,
    expect,
    it,
} from 'vitest';

import packageManifest from '../../../package.json';

describe(
    'Frontend production artifact security gate command contract',
    () => {
        it('exposes the canonical production artifact security verification command', () => {
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
                    'frontend:artifact:security'
                ],
            ).toBe(
                'node scripts/frontend-artifact-security-check.mjs',
            );
        });
    },
);
