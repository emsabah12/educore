import type {
    BrowserApiProtocolFailure,
} from '@/platform/api';
import type {
    CanonicalMembershipContext,
} from '@/platform/membership/contract';
import type {
    OrganizationUnitWorkspaceSummary,
    OrganizationWorkspaceSummary,
    WorkspaceSummary,
} from '@/platform/workspace/contract';
import type {
    WorkspaceContextVerifier,
    WorkspaceVerificationOptions,
    WorkspaceVerificationResult,
} from '@/platform/workspace/verification';

import type {
    CapabilityProjectionOperations,
} from '@/platform/authorization/operations';
import {
    validateTenantCapabilityProjection,
    validateWorkspaceCapabilityProjection,
    type CapabilityProjectionValidationResult,
    type WorkspaceCapabilityScopeExpectation,
} from '@/platform/authorization/validation';

type OrganizationalWorkspaceSummary =
    | OrganizationWorkspaceSummary
    | OrganizationUnitWorkspaceSummary;

function createProtocolFailure(
    status:
        number,
): BrowserApiProtocolFailure {
    return {
        ok:
            false,

        kind:
            'protocol',

        status,

        message:
            'EduCore API returned an unexpected error response.',
    };
}

function verificationFromValidation<Data>(
    validation:
        CapabilityProjectionValidationResult<Data>,
    status:
        number,
): WorkspaceVerificationResult {
    if (
        ! validation.ok
    ) {
        /*
         * A successful transport response whose payload is
         * malformed or bound to another canonical scope is
         * a protocol violation from the Workspace verifier's
         * perspective.
         *
         * WorkspaceVerificationResult intentionally exposes
         * only BrowserApiFailure, so semantic transport
         * contradictions fail closed as protocol failures.
         */
        return createProtocolFailure(
            status,
        );
    }

    return {
        ok:
            true,
    };
}

function expectationFromWorkspace(
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

async function verifyTenantWorkspace(
    operations:
        CapabilityProjectionOperations,
    context:
        CanonicalMembershipContext,
    options:
        WorkspaceVerificationOptions,
): Promise<
    WorkspaceVerificationResult
> {
    const result =
        await operations.projectTenant(
            context.membership.id,
            options,
        );

    if (
        ! result.ok
    ) {
        return result;
    }

    return verificationFromValidation(
        validateTenantCapabilityProjection(
            context,
            result.data,
        ),
        result.status,
    );
}

async function verifyOrganizationalWorkspace(
    operations:
        CapabilityProjectionOperations,
    context:
        CanonicalMembershipContext,
    workspace:
        OrganizationalWorkspaceSummary,
    options:
        WorkspaceVerificationOptions,
): Promise<
    WorkspaceVerificationResult
> {
    const result =
        await operations.projectWorkspace(
            context.membership.id,
            workspace
                .organizational_assignment_id,
            options,
        );

    if (
        ! result.ok
    ) {
        /*
         * Preserve canonical failures unchanged.
         *
         * ORGANIZATIONAL_CONTEXT_DENIED in particular must
         * remain observable by WorkspaceContextRuntime so
         * stale Workspace recovery can execute.
         */
        return result;
    }

    return verificationFromValidation(
        validateWorkspaceCapabilityProjection(
            context,
            expectationFromWorkspace(
                workspace,
            ),
            result.data,
        ),
        result.status,
    );
}

export function createWorkspaceCapabilityVerifier(
    operations:
        CapabilityProjectionOperations,
): WorkspaceContextVerifier {
    return {
        verify(
            context,
            workspace:
                WorkspaceSummary,
            options = {},
        ) {
            if (
                workspace.type
                    === 'TENANT'
            ) {
                return verifyTenantWorkspace(
                    operations,
                    context,
                    options,
                );
            }

            return verifyOrganizationalWorkspace(
                operations,
                context,
                workspace,
                options,
            );
        },
    };
}
