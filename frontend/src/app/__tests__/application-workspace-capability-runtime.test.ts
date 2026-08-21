import {
    describe,
    expect,
    it,
} from 'vitest';

import {
    createApplicationRuntime,
} from '@/app/runtime';

describe(
    'Application Workspace and Capability runtimes',
    () => {
        it('composes unresolved Workspace and Capability runtimes without eagerly dispatching lifecycle work', async () => {
            const runtime =
                createApplicationRuntime();

            expect(
                runtime.workspace
                    .getState(),
            ).toEqual({
                status:
                    'unresolved',
            });

            expect(
                runtime.capabilities
                    .getState(),
            ).toEqual({
                status:
                    'unresolved',
            });

            /*
             * Capability bootstrap cannot invent authority
             * while Membership and Workspace are not ready.
             */
            await expect(
                runtime.capabilities
                    .bootstrap(),
            ).resolves.toEqual({
                status:
                    'unresolved',
            });

            /*
             * Workspace bootstrap likewise remains dormant
             * while canonical Membership/Tenant truth has
             * not become ready.
             */
            await expect(
                runtime.workspace
                    .bootstrap(),
            ).resolves.toEqual({
                status:
                    'unresolved',
            });

            expect(
                runtime.capabilities
                    .getState(),
            ).toEqual({
                status:
                    'unresolved',
            });

            runtime.capabilities
                .dispose();

            runtime.workspace
                .dispose();
        });

        it('keeps Capability authority unresolved when Workspace has no committed context', () => {
            const runtime =
                createApplicationRuntime();

            expect(
                runtime.membership
                    .getState(),
            ).toEqual({
                status:
                    'unresolved',
            });

            expect(
                runtime.workspace
                    .getState(),
            ).toEqual({
                status:
                    'unresolved',
            });

            expect(
                runtime.capabilities
                    .getState(),
            ).toEqual({
                status:
                    'unresolved',
            });

            runtime.capabilities
                .dispose();

            runtime.workspace
                .dispose();
        });
    },
);
