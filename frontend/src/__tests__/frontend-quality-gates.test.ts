import {
    describe,
    expect,
    it,
} from 'vitest';

import packageManifest from '../../../package.json';

describe(
    'Frontend quality gate command contract',
    () => {
        it('exposes canonical lint and formatting verification commands', () => {
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
                    'frontend:lint'
                ],
            ).toBeTypeOf(
                'string',
            );

            expect(
                scripts[
                    'frontend:format:check'
                ],
            ).toBeTypeOf(
                'string',
            );
        });
    },
);
