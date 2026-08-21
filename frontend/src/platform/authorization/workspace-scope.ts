import type {
    OrganizationUnitWorkspaceSummary,
    OrganizationWorkspaceSummary,
} from '@/platform/workspace/contract';

import type {
    WorkspaceCapabilityScopeExpectation,
} from '@/platform/authorization/validation';

export type OrganizationalWorkspaceSummary =
    | OrganizationWorkspaceSummary
    | OrganizationUnitWorkspaceSummary;

export function createWorkspaceCapabilityScopeExpectation(
    workspace:
        OrganizationalWorkspaceSummary,
): WorkspaceCapabilityScopeExpectation {
    if (
        workspace.type
            === 'ORGANIZATION'
    ) {
        return {
            type:
                'organization',

            organizationalAssignmentId:
                workspace
                    .organizational_assignment_id,

            organizationId:
                workspace.organization_id,

            organizationUnitId:
                null,
        };
    }

    return {
        type:
            'organization_unit',

        organizationalAssignmentId:
            workspace
                .organizational_assignment_id,

        organizationId:
            workspace.organization_id,

        organizationUnitId:
            workspace
                .organization_unit_id,
    };
}
