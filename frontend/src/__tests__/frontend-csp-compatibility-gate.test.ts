import {
    describe,
    expect,
    it,
} from 'vitest';

import packageManifest from '../../../package.json';

describe(
    'Frontend CSP compatibility gate command contract',
    () => {
        it('exposes the canonical production CSP compatibility verification command', () => {
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
                    'frontend:csp:check'
                ],
            ).toBe(
                'node scripts/frontend-csp-compatibility-check.mjs',
            );
        });
    },
);
