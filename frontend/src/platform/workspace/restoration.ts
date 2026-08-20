import type {
    OrganizationalAssignmentLocator,
} from '@/platform/api';
import type {
    CanonicalMembershipContext,
} from '@/platform/membership';

import type {
    WorkspaceSummary,
} from '@/platform/workspace/contract';

const WORKSPACE_RESTORATION_HINT_VERSION =
    1 as const;

const WORKSPACE_RESTORATION_HINT_STORAGE_KEY =
    'educore.workspace-restoration.v1';

export interface WorkspaceRestorationHint {
    readonly version:
        typeof WORKSPACE_RESTORATION_HINT_VERSION;

    readonly membershipId:
        CanonicalMembershipContext[
            'membership'
        ]['id'];

    readonly tenantId:
        CanonicalMembershipContext[
            'tenant'
        ]['id'];

    readonly organizationalAssignmentId:
        OrganizationalAssignmentLocator;
}

export type WorkspaceRestorationHintStorage =
    Pick<
        Storage,
        | 'getItem'
        | 'setItem'
        | 'removeItem'
    >;

export interface WorkspaceRestorationSuccess {
    readonly ok:
        true;
}

export interface WorkspaceRestorationReadSuccess {
    readonly ok:
        true;

    readonly hint:
        WorkspaceRestorationHint | null;
}

export interface WorkspaceRestorationFailure {
    readonly ok:
        false;

    readonly kind:
        'invalid'
        | 'storage';

    readonly cause:
        unknown;
}

export type WorkspaceRestorationMutationResult =
    | WorkspaceRestorationSuccess
    | WorkspaceRestorationFailure;

export type WorkspaceRestorationReadResult =
    | WorkspaceRestorationReadSuccess
    | WorkspaceRestorationFailure;

function isRecord(
    value: unknown,
): value is Record<
    string,
    unknown
> {
    return (
        typeof value
            === 'object'
        && value !== null
        && ! Array.isArray(
            value,
        )
    );
}

function isNonEmptyString(
    value: unknown,
): value is string {
    return (
        typeof value
            === 'string'
        && value.trim()
            !== ''
    );
}

function isWorkspaceRestorationHint(
    value: unknown,
): value is WorkspaceRestorationHint {
    if (
        ! isRecord(
            value,
        )
    ) {
        return false;
    }

    return (
        value.version
            === WORKSPACE_RESTORATION_HINT_VERSION
        && isNonEmptyString(
            value.membershipId,
        )
        && isNonEmptyString(
            value.tenantId,
        )
        && isNonEmptyString(
            value.organizationalAssignmentId,
        )
    );
}

function resolveStorage(
    storage:
        WorkspaceRestorationHintStorage
        | undefined,
): WorkspaceRestorationHintStorage {
    if (
        storage !== undefined
    ) {
        return storage;
    }

    if (
        typeof window
            === 'undefined'
    ) {
        throw new Error(
            'EduCore Workspace restoration requires a browser runtime.',
        );
    }

    return window.sessionStorage;
}

function createStorageFailure(
    cause: unknown,
): WorkspaceRestorationFailure {
    return {
        ok:
            false,
        kind:
            'storage',
        cause,
    };
}

function discardInvalidHint(
    storage:
        WorkspaceRestorationHintStorage,
    cause: unknown,
): WorkspaceRestorationFailure {
    try {
        storage.removeItem(
            WORKSPACE_RESTORATION_HINT_STORAGE_KEY,
        );
    } catch (storageCause: unknown) {
        return createStorageFailure(
            storageCause,
        );
    }

    return {
        ok:
            false,
        kind:
            'invalid',
        cause,
    };
}

export function readBrowserWorkspaceRestorationHint(
    storage?:
        WorkspaceRestorationHintStorage,
): WorkspaceRestorationReadResult {
    let resolvedStorage:
        WorkspaceRestorationHintStorage;

    try {
        resolvedStorage =
            resolveStorage(
                storage,
            );
    } catch (cause: unknown) {
        return createStorageFailure(
            cause,
        );
    }

    let serialized:
        string | null;

    try {
        serialized =
            resolvedStorage.getItem(
                WORKSPACE_RESTORATION_HINT_STORAGE_KEY,
            );
    } catch (cause: unknown) {
        return createStorageFailure(
            cause,
        );
    }

    if (
        serialized === null
    ) {
        return {
            ok:
                true,
            hint:
                null,
        };
    }

    let parsed:
        unknown;

    try {
        parsed =
            JSON.parse(
                serialized,
            );
    } catch (cause: unknown) {
        return discardInvalidHint(
            resolvedStorage,
            cause,
        );
    }

    if (
        ! isWorkspaceRestorationHint(
            parsed,
        )
    ) {
        return discardInvalidHint(
            resolvedStorage,
            new Error(
                'Stored EduCore Workspace restoration hint is invalid.',
            ),
        );
    }

    return {
        ok:
            true,
        hint:
            parsed,
    };
}

export function clearBrowserWorkspaceRestorationHint(
    storage?:
        WorkspaceRestorationHintStorage,
): WorkspaceRestorationMutationResult {
    try {
        const resolvedStorage =
            resolveStorage(
                storage,
            );

        resolvedStorage.removeItem(
            WORKSPACE_RESTORATION_HINT_STORAGE_KEY,
        );

        return {
            ok:
                true,
        };
    } catch (cause: unknown) {
        return createStorageFailure(
            cause,
        );
    }
}

export function persistBrowserWorkspaceRestorationHint(
    context:
        CanonicalMembershipContext,
    workspace:
        WorkspaceSummary,
    storage?:
        WorkspaceRestorationHintStorage,
): WorkspaceRestorationMutationResult {
    /*
     * TENANT is the safe baseline and needs no restoration
     * locator. Clearing the hint also prevents an old
     * organizational assignment from being restored after
     * the user intentionally returns to Tenant scope.
     */
    if (
        workspace.type
            === 'TENANT'
    ) {
        return clearBrowserWorkspaceRestorationHint(
            storage,
        );
    }

    if (
        ! isNonEmptyString(
            context.membership.id,
        )
        || ! isNonEmptyString(
            context.tenant.id,
        )
        || ! isNonEmptyString(
            workspace
                .organizational_assignment_id,
        )
    ) {
        return {
            ok:
                false,
            kind:
                'invalid',
            cause:
                new Error(
                    'EduCore Workspace restoration requires canonical Membership, Tenant, and organizational assignment identifiers.',
                ),
        };
    }

    const hint:
        WorkspaceRestorationHint = {
            version:
                WORKSPACE_RESTORATION_HINT_VERSION,

            membershipId:
                context.membership.id,

            tenantId:
                context.tenant.id,

            organizationalAssignmentId:
                workspace
                    .organizational_assignment_id,
        };

    try {
        const resolvedStorage =
            resolveStorage(
                storage,
            );

        resolvedStorage.setItem(
            WORKSPACE_RESTORATION_HINT_STORAGE_KEY,
            JSON.stringify(
                hint,
            ),
        );

        return {
            ok:
                true,
        };
    } catch (cause: unknown) {
        return createStorageFailure(
            cause,
        );
    }
}

export function resolveWorkspaceRestorationTarget(
    context:
        CanonicalMembershipContext,
    workspaces:
        readonly WorkspaceSummary[],
    hint:
        WorkspaceRestorationHint
        | null,
): WorkspaceSummary | null {
    if (
        hint === null
    ) {
        return null;
    }

    if (
        hint.membershipId
            !== context.membership.id
        || hint.tenantId
            !== context.tenant.id
    ) {
        return null;
    }

    const matches =
        workspaces.filter(
            (workspace) => {
                if (
                    workspace.type
                        === 'TENANT'
                ) {
                    return false;
                }

                return (
                    workspace
                        .organizational_assignment_id
                    === hint
                        .organizationalAssignmentId
                );
            },
        );

    if (
        matches.length
            !== 1
    ) {
        return null;
    }

    const match =
        matches[0];

    if (
        match === undefined
    ) {
        return null;
    }

    /*
     * Return the fresh canonical catalog object.
     *
     * Never reconstruct Workspace authority from storage.
     */
    return match;
}
