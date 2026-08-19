import type { ApiComponents } from '@/platform/api/contract';

export type BrowserMembershipLocator =
    ApiComponents['parameters']['CanonicalBrowserMembershipLocator'];

export type OrganizationalAssignmentLocator =
    ApiComponents['parameters']['OrganizationalAssignmentId'];

export interface BrowserMembershipRequestContext {
    readonly membershipId: BrowserMembershipLocator;
}

export interface BrowserWorkspaceRequestContext
    extends BrowserMembershipRequestContext {
    readonly organizationalAssignmentId:
        OrganizationalAssignmentLocator;
}

export function createBrowserMembershipHeaderParams(
    context: BrowserMembershipRequestContext,
): {
    'X-EduCore-Membership-Id': BrowserMembershipLocator;
} {
    return {
        'X-EduCore-Membership-Id':
            context.membershipId,
    };
}

export function createBrowserWorkspaceHeaderParams(
    context: BrowserWorkspaceRequestContext,
): {
    'X-EduCore-Membership-Id': BrowserMembershipLocator;
    'X-EduCore-Organizational-Assignment-Id':
        OrganizationalAssignmentLocator;
} {
    return {
        'X-EduCore-Membership-Id':
            context.membershipId,

        'X-EduCore-Organizational-Assignment-Id':
            context.organizationalAssignmentId,
    };
}
