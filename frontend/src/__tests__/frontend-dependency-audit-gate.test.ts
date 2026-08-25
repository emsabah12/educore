import {
    describe,
    expect,
    it,
} from 'vitest';

import packageManifest from '../../../package.json';

describe(
    'Frontend dependency audit gate command contract',
    () => {
        it('exposes the canonical high-severity dependency audit command', () => {
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
                    'frontend:dependencies:audit'
                ],
            ).toBe(
                'npm audit --audit-level=high',
            );
        });
    },
);
