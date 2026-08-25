import {
    describe,
    expect,
    it,
} from 'vitest';

import packageManifest from '../../../package.json';

const expectedVerificationCommands = [
    'npm run frontend:format:check',
    'npm run frontend:lint',
    'npm run frontend:typecheck',
    'npm run frontend:api:check',
    'npm run frontend:dependencies:audit',
    'npm run frontend:test',
    'npm run frontend:build',
    'npm run frontend:artifact:security',
    'npm run frontend:bundle:check',
    'npm run frontend:csp:check',
] as const;

describe(
    'Frontend aggregate verification gate command contract',
    () => {
        it('exposes the canonical fail-fast frontend verification command', () => {
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
                    'frontend:verify'
                ],
            ).toBe(
                expectedVerificationCommands.join(
                    ' && ',
                ),
            );
        });
    },
);
