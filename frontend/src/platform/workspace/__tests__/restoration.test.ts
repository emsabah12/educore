import {
    afterEach,
    describe,
    expect,
    it,
} from 'vitest';

import type {
    CanonicalMembershipContext,
} from '@/platform/membership';
import {
    clearBrowserWorkspaceRestorationHint,
    persistBrowserWorkspaceRestorationHint,
    readBrowserWorkspaceRestorationHint,
    resolveWorkspaceRestorationTarget,
    type WorkspaceRestorationHint,
    type WorkspaceRestorationHintStorage,
    type WorkspaceSummary,
} from '@/platform/workspace';

const membershipId =
    '018f3b6a-7c20-7abc-8def-1234567890ab';

const otherMembershipId =
    '018f3b6a-7c20-7bcd-8def-1234567890ab';

const tenantId =
    '018f3b6a-7c20-7cde-8def-1234567890ab';

const otherTenantId =
    '018f3b6a-7c20-7def-8abc-1234567890ab';

const organizationalAssignmentId =
    '018f3b6a-7c20-7def-9abc-1234567890ab';

const unitAssignmentId =
    '018f3b6a-7c20-7abc-9abc-1234567890ab';

const organizationId =
    '018f3b6a-7c20-7bcd-9abc-1234567890ab';

const organizationUnitId =
    '018f3b6a-7c20-7cde-9abc-1234567890ab';

const context:
    CanonicalMembershipContext = {
        membership: {
            id:
                membershipId,
            status:
                'ACTIVE',
        },

        tenant: {
            id:
                tenantId,
            name:
                'EduCore School',
            subdomain:
                'educore-school',
        },
    };

const tenantWorkspace:
    WorkspaceSummary = {
        type:
            'TENANT',
        organizational_assignment_id:
            null,
        organization_id:
            null,
        organization_unit_id:
            null,
        label:
            'EduCore School',
    };

const organizationWorkspace:
    WorkspaceSummary = {
        type:
            'ORGANIZATION',
        organizational_assignment_id:
            organizationalAssignmentId,
        organization_id:
            organizationId,
        organization_unit_id:
            null,
        label:
            'SMA EduCore',
    };

const unitWorkspace:
    WorkspaceSummary = {
        type:
            'ORGANIZATION_UNIT',
        organizational_assignment_id:
            unitAssignmentId,
        organization_id:
            organizationId,
        organization_unit_id:
            organizationUnitId,
        label:
            'Unit Kurikulum',
    };

class MemoryWorkspaceStorage
    implements WorkspaceRestorationHintStorage {
    private readonly values =
        new Map<
            string,
            string
        >();

    public failGet =
        false;

    public failSet =
        false;

    public failRemove =
        false;

    public getItem(
        key: string,
    ): string | null {
        if (
            this.failGet
        ) {
            throw new Error(
                'Storage read unavailable.',
            );
        }

        return (
            this.values.get(
                key,
            )
            ?? null
        );
    }

    public setItem(
        key: string,
        value: string,
    ): void {
        if (
            this.failSet
        ) {
            throw new Error(
                'Storage write unavailable.',
            );
        }

        this.values.set(
            key,
            value,
        );
    }

    public removeItem(
        key: string,
    ): void {
        if (
            this.failRemove
        ) {
            throw new Error(
                'Storage removal unavailable.',
            );
        }

        this.values.delete(
            key,
        );
    }

    public getStoredValue():
        string | null {
        const firstValue =
            this.values.values()
                .next();

        return firstValue.done
            ? null
            : firstValue.value;
    }

    public overwriteStoredValue(
        value: string,
    ): void {
        const firstKey =
            this.values.keys()
                .next();

        if (
            firstKey.done
        ) {
            throw new Error(
                'Expected a stored Workspace restoration hint.',
            );
        }

        this.values.set(
            firstKey.value,
            value,
        );
    }
}

function readStoredHint(
    storage:
        WorkspaceRestorationHintStorage,
): WorkspaceRestorationHint {
    const result =
        readBrowserWorkspaceRestorationHint(
            storage,
        );

    if (
        ! result.ok
        || result.hint
            === null
    ) {
        throw new Error(
            'Expected a valid Workspace restoration hint.',
        );
    }

    return result.hint;
}

afterEach(() => {
    window.sessionStorage.clear();
});

describe(
    'Workspace restoration hint',
    () => {
        it('persists only the minimal organizational restoration locator', () => {
            const storage =
                new MemoryWorkspaceStorage();

            expect(
                persistBrowserWorkspaceRestorationHint(
                    context,
                    organizationWorkspace,
                    storage,
                ),
            ).toEqual({
                ok:
                    true,
            });

            expect(
                readStoredHint(
                    storage,
                ),
            ).toEqual({
                version:
                    1,
                membershipId,
                tenantId,
                organizationalAssignmentId,
            });

            const serialized =
                storage.getStoredValue();

            expect(
                serialized,
            ).not.toBeNull();

            expect(
                serialized,
            ).not.toContain(
                'SMA EduCore',
            );

            expect(
                serialized,
            ).not.toContain(
                organizationId,
            );
        });

        it('clears the restoration hint when TENANT becomes current', () => {
            const storage =
                new MemoryWorkspaceStorage();

            expect(
                persistBrowserWorkspaceRestorationHint(
                    context,
                    organizationWorkspace,
                    storage,
                ),
            ).toEqual({
                ok:
                    true,
            });

            expect(
                storage.getStoredValue(),
            ).not.toBeNull();

            expect(
                persistBrowserWorkspaceRestorationHint(
                    context,
                    tenantWorkspace,
                    storage,
                ),
            ).toEqual({
                ok:
                    true,
            });

            expect(
                readBrowserWorkspaceRestorationHint(
                    storage,
                ),
            ).toEqual({
                ok:
                    true,
                hint:
                    null,
            });
        });

        it('uses browser sessionStorage by default', () => {
            expect(
                persistBrowserWorkspaceRestorationHint(
                    context,
                    unitWorkspace,
                ),
            ).toEqual({
                ok:
                    true,
            });

            expect(
                readBrowserWorkspaceRestorationHint(),
            ).toEqual({
                ok:
                    true,
                hint: {
                    version:
                        1,
                    membershipId,
                    tenantId,
                    organizationalAssignmentId:
                        unitAssignmentId,
                },
            });

            expect(
                clearBrowserWorkspaceRestorationHint(),
            ).toEqual({
                ok:
                    true,
            });
        });

        it('resolves restoration only to the fresh canonical Workspace catalog object', () => {
            const storage =
                new MemoryWorkspaceStorage();

            persistBrowserWorkspaceRestorationHint(
                context,
                organizationWorkspace,
                storage,
            );

            const hint =
                readStoredHint(
                    storage,
                );

            const refreshedWorkspace:
                WorkspaceSummary = {
                    ...organizationWorkspace,
                    label:
                        'SMA EduCore Renamed',
                };

            const resolved =
                resolveWorkspaceRestorationTarget(
                    context,
                    [
                        tenantWorkspace,
                        refreshedWorkspace,
                        unitWorkspace,
                    ],
                    hint,
                );

            expect(
                resolved,
            ).toBe(
                refreshedWorkspace,
            );

            expect(
                resolved?.label,
            ).toBe(
                'SMA EduCore Renamed',
            );
        });

        it('rejects a restoration hint created under another Membership', () => {
            const hint:
                WorkspaceRestorationHint = {
                    version:
                        1,
                    membershipId:
                        otherMembershipId,
                    tenantId,
                    organizationalAssignmentId,
                };

            expect(
                resolveWorkspaceRestorationTarget(
                    context,
                    [
                        tenantWorkspace,
                        organizationWorkspace,
                    ],
                    hint,
                ),
            ).toBeNull();
        });

        it('rejects a restoration hint created under another Tenant', () => {
            const hint:
                WorkspaceRestorationHint = {
                    version:
                        1,
                    membershipId,
                    tenantId:
                        otherTenantId,
                    organizationalAssignmentId,
                };

            expect(
                resolveWorkspaceRestorationTarget(
                    context,
                    [
                        tenantWorkspace,
                        organizationWorkspace,
                    ],
                    hint,
                ),
            ).toBeNull();
        });

        it('rejects a stale assignment missing from fresh discovery', () => {
            const hint:
                WorkspaceRestorationHint = {
                    version:
                        1,
                    membershipId,
                    tenantId,
                    organizationalAssignmentId:
                        '018f3b6a-7c20-7def-9def-1234567890ab',
                };

            expect(
                resolveWorkspaceRestorationTarget(
                    context,
                    [
                        tenantWorkspace,
                        organizationWorkspace,
                        unitWorkspace,
                    ],
                    hint,
                ),
            ).toBeNull();
        });

        it('rejects an ambiguous assignment instead of selecting arbitrarily', () => {
            const hint:
                WorkspaceRestorationHint = {
                    version:
                        1,
                    membershipId,
                    tenantId,
                    organizationalAssignmentId,
                };

            const duplicateAssignment:
                WorkspaceSummary = {
                    type:
                        'ORGANIZATION_UNIT',
                    organizational_assignment_id:
                        organizationalAssignmentId,
                    organization_id:
                        organizationId,
                    organization_unit_id:
                        organizationUnitId,
                    label:
                        'Ambiguous Unit',
                };

            expect(
                resolveWorkspaceRestorationTarget(
                    context,
                    [
                        tenantWorkspace,
                        organizationWorkspace,
                        duplicateAssignment,
                    ],
                    hint,
                ),
            ).toBeNull();
        });

        it('fails closed and discards malformed serialized hints', () => {
            const storage =
                new MemoryWorkspaceStorage();

            persistBrowserWorkspaceRestorationHint(
                context,
                organizationWorkspace,
                storage,
            );

            storage.overwriteStoredValue(
                '{not-valid-json',
            );

            expect(
                readBrowserWorkspaceRestorationHint(
                    storage,
                ),
            ).toMatchObject({
                ok:
                    false,
                kind:
                    'invalid',
            });

            expect(
                storage.getStoredValue(),
            ).toBeNull();
        });

        it('fails closed and discards structurally invalid hints', () => {
            const storage =
                new MemoryWorkspaceStorage();

            persistBrowserWorkspaceRestorationHint(
                context,
                organizationWorkspace,
                storage,
            );

            storage.overwriteStoredValue(
                JSON.stringify({
                    version:
                        1,
                    membershipId,
                    tenantId,
                    organizationalAssignmentId:
                        '',
                }),
            );

            expect(
                readBrowserWorkspaceRestorationHint(
                    storage,
                ),
            ).toMatchObject({
                ok:
                    false,
                kind:
                    'invalid',
            });

            expect(
                storage.getStoredValue(),
            ).toBeNull();
        });

        it('reports storage failures without throwing or inventing Workspace authority', () => {
            const readStorage =
                new MemoryWorkspaceStorage();

            readStorage.failGet =
                true;

            expect(
                readBrowserWorkspaceRestorationHint(
                    readStorage,
                ),
            ).toMatchObject({
                ok:
                    false,
                kind:
                    'storage',
            });

            const writeStorage =
                new MemoryWorkspaceStorage();

            writeStorage.failSet =
                true;

            expect(
                persistBrowserWorkspaceRestorationHint(
                    context,
                    organizationWorkspace,
                    writeStorage,
                ),
            ).toMatchObject({
                ok:
                    false,
                kind:
                    'storage',
            });

            expect(
                writeStorage.getStoredValue(),
            ).toBeNull();
        });
    },
);
