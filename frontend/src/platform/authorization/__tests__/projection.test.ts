import {
    http,
    HttpResponse,
} from 'msw';
import {
    describe,
    expect,
    it,
} from 'vitest';

import {
    createBrowserApiClient,
} from '@/platform/api';
import {
    projectBrowserTenantCapabilities,
    projectBrowserWorkspaceCapabilities,
    type TenantCapabilitySuccess,
    type WorkspaceCapabilitySuccess,
} from '@/platform/authorization';
import {
    apiMockServer,
} from '@/test/server';

const membershipId =
    '018f3b6a-7c20-7abc-8def-1234567890ab';

const tenantId =
    '018f3b6a-7c20-7cde-8def-1234567890ab';

const organizationalAssignmentId =
    '018f3b6a-7c20-7def-9abc-1234567890ab';

const organizationId =
    '018f3b6a-7c20-7abc-9def-1234567890ab';

const tenantCapabilitySuccess:
    TenantCapabilitySuccess = {
        status:
            'success',

        data: {
            scope: {
                type:
                    'tenant',
                tenant_id:
                    tenantId,
                membership_id:
                    membershipId,
            },

            is_global_superadmin:
                false,

            permissions: [
                'academic.grades.write',
            ],
        },
    };

const workspaceCapabilitySuccess:
    WorkspaceCapabilitySuccess = {
        status:
            'success',

        data: {
            scope: {
                type:
                    'organization',
                tenant_id:
                    tenantId,
                membership_id:
                    membershipId,
                organizational_assignment_id:
                    organizationalAssignmentId,
                organization_id:
                    organizationId,
                organization_unit_id:
                    null,
            },

            is_global_superadmin:
                false,

            permissions: [
                'academic.grades.write',
                'dormitory.rooms.manage',
            ],
        },
    };

describe(
    'Browser capability projection',
    () => {
        it('projects TENANT capabilities with only the explicit Membership locator', async () => {
            let observedMembershipId:
                string | null = null;

            let observedOrganizationalAssignmentId:
                string | null = null;

            apiMockServer.use(
                http.get(
                    `${window.location.origin}/api/v1/core/authorization/capabilities`,
                    ({
                        request,
                    }) => {
                        observedMembershipId =
                            request.headers.get(
                                'X-EduCore-Membership-Id',
                            );

                        observedOrganizationalAssignmentId =
                            request.headers.get(
                                'X-EduCore-Organizational-Assignment-Id',
                            );

                        return HttpResponse.json(
                            tenantCapabilitySuccess,
                        );
                    },
                ),
            );

            const result =
                await projectBrowserTenantCapabilities(
                    createBrowserApiClient(),
                    membershipId,
                );

            expect(result).toEqual({
                ok:
                    true,
                status:
                    200,
                data:
                    tenantCapabilitySuccess,
            });

            expect(
                observedMembershipId,
            ).toBe(
                membershipId,
            );

            expect(
                observedOrganizationalAssignmentId,
            ).toBeNull();
        });

        it('retries transient TENANT capability failure while preserving only the Membership locator', async () => {
            let attempts =
                0;

            const observedMembershipIds:
                Array<string | null> = [];

            const observedOrganizationalAssignmentIds:
                Array<string | null> = [];

            apiMockServer.use(
                http.get(
                    `${window.location.origin}/api/v1/core/authorization/capabilities`,
                    ({
                        request,
                    }) => {
                        attempts +=
                            1;

                        observedMembershipIds.push(
                            request.headers.get(
                                'X-EduCore-Membership-Id',
                            ),
                        );

                        observedOrganizationalAssignmentIds.push(
                            request.headers.get(
                                'X-EduCore-Organizational-Assignment-Id',
                            ),
                        );

                        if (
                            attempts
                                === 1
                        ) {
                            return HttpResponse
                                .error();
                        }

                        return HttpResponse.json(
                            tenantCapabilitySuccess,
                        );
                    },
                ),
            );

            const result =
                await projectBrowserTenantCapabilities(
                    createBrowserApiClient(),
                    membershipId,
                );

            expect(attempts).toBe(2);

            expect(
                observedMembershipIds,
            ).toEqual([
                membershipId,
                membershipId,
            ]);

            expect(
                observedOrganizationalAssignmentIds,
            ).toEqual([
                null,
                null,
            ]);

            expect(result).toEqual({
                ok:
                    true,
                status:
                    200,
                data:
                    tenantCapabilitySuccess,
            });
        });

        it('projects organizational Workspace capabilities with both explicit locators', async () => {
            let observedMembershipId:
                string | null = null;

            let observedOrganizationalAssignmentId:
                string | null = null;

            apiMockServer.use(
                http.get(
                    `${window.location.origin}/api/v1/core/authorization/workspace-capabilities`,
                    ({
                        request,
                    }) => {
                        observedMembershipId =
                            request.headers.get(
                                'X-EduCore-Membership-Id',
                            );

                        observedOrganizationalAssignmentId =
                            request.headers.get(
                                'X-EduCore-Organizational-Assignment-Id',
                            );

                        return HttpResponse.json(
                            workspaceCapabilitySuccess,
                        );
                    },
                ),
            );

            const result =
                await projectBrowserWorkspaceCapabilities(
                    createBrowserApiClient(),
                    membershipId,
                    organizationalAssignmentId,
                );

            expect(result).toEqual({
                ok:
                    true,
                status:
                    200,
                data:
                    workspaceCapabilitySuccess,
            });

            expect(
                observedMembershipId,
            ).toBe(
                membershipId,
            );

            expect(
                observedOrganizationalAssignmentId,
            ).toBe(
                organizationalAssignmentId,
            );
        });

        it('retries transient Workspace capability failure while preserving both explicit locators', async () => {
            let attempts =
                0;

            const observedMembershipIds:
                Array<string | null> = [];

            const observedOrganizationalAssignmentIds:
                Array<string | null> = [];

            apiMockServer.use(
                http.get(
                    `${window.location.origin}/api/v1/core/authorization/workspace-capabilities`,
                    ({
                        request,
                    }) => {
                        attempts +=
                            1;

                        observedMembershipIds.push(
                            request.headers.get(
                                'X-EduCore-Membership-Id',
                            ),
                        );

                        observedOrganizationalAssignmentIds.push(
                            request.headers.get(
                                'X-EduCore-Organizational-Assignment-Id',
                            ),
                        );

                        if (
                            attempts
                                === 1
                        ) {
                            return HttpResponse
                                .error();
                        }

                        return HttpResponse.json(
                            workspaceCapabilitySuccess,
                        );
                    },
                ),
            );

            const result =
                await projectBrowserWorkspaceCapabilities(
                    createBrowserApiClient(),
                    membershipId,
                    organizationalAssignmentId,
                );

            expect(attempts).toBe(2);

            expect(
                observedMembershipIds,
            ).toEqual([
                membershipId,
                membershipId,
            ]);

            expect(
                observedOrganizationalAssignmentIds,
            ).toEqual([
                organizationalAssignmentId,
                organizationalAssignmentId,
            ]);

            expect(result).toEqual({
                ok:
                    true,
                status:
                    200,
                data:
                    workspaceCapabilitySuccess,
            });
        });

        it('preserves BrowserSession authentication-required failures', async () => {
            apiMockServer.use(
                http.get(
                    `${window.location.origin}/api/v1/core/authorization/capabilities`,
                    () =>
                        HttpResponse.json(
                            {
                                status:
                                    'error',
                                code:
                                    'BROWSER_SESSION_AUTHENTICATION_REQUIRED',
                                message:
                                    'Authenticated browser session is required.',
                            },
                            {
                                status:
                                    401,
                            },
                        ),
                ),
            );

            const result =
                await projectBrowserTenantCapabilities(
                    createBrowserApiClient(),
                    membershipId,
                );

            expect(result).toMatchObject({
                ok:
                    false,
                kind:
                    'response',
                status:
                    401,
                error: {
                    code:
                        'BROWSER_SESSION_AUTHENTICATION_REQUIRED',
                },
            });
        });

        it('preserves missing Browser Membership context failures', async () => {
            apiMockServer.use(
                http.get(
                    `${window.location.origin}/api/v1/core/authorization/capabilities`,
                    () =>
                        HttpResponse.json(
                            {
                                status:
                                    'error',
                                code:
                                    'BROWSER_MEMBERSHIP_CONTEXT_REQUIRED',
                                message:
                                    'Browser membership context is required.',
                            },
                            {
                                status:
                                    403,
                            },
                        ),
                ),
            );

            const result =
                await projectBrowserTenantCapabilities(
                    createBrowserApiClient(),
                    membershipId,
                );

            expect(result).toMatchObject({
                ok:
                    false,
                kind:
                    'response',
                status:
                    403,
                error: {
                    code:
                        'BROWSER_MEMBERSHIP_CONTEXT_REQUIRED',
                },
            });
        });

        it('preserves invalid Browser Membership locator failures', async () => {
            apiMockServer.use(
                http.get(
                    `${window.location.origin}/api/v1/core/authorization/capabilities`,
                    () =>
                        HttpResponse.json(
                            {
                                status:
                                    'error',
                                code:
                                    'INVALID_BROWSER_MEMBERSHIP_ID',
                                message:
                                    'Browser membership identifier is invalid.',
                            },
                            {
                                status:
                                    422,
                            },
                        ),
                ),
            );

            const result =
                await projectBrowserTenantCapabilities(
                    createBrowserApiClient(),
                    membershipId,
                );

            expect(result).toMatchObject({
                ok:
                    false,
                kind:
                    'response',
                status:
                    422,
                error: {
                    code:
                        'INVALID_BROWSER_MEMBERSHIP_ID',
                },
            });
        });

        it('preserves missing organizational context failures', async () => {
            apiMockServer.use(
                http.get(
                    `${window.location.origin}/api/v1/core/authorization/workspace-capabilities`,
                    () =>
                        HttpResponse.json(
                            {
                                status:
                                    'error',
                                code:
                                    'ORGANIZATIONAL_CONTEXT_REQUIRED',
                                message:
                                    'Organizational workspace is required for this operation.',
                            },
                            {
                                status:
                                    403,
                            },
                        ),
                ),
            );

            const result =
                await projectBrowserWorkspaceCapabilities(
                    createBrowserApiClient(),
                    membershipId,
                    organizationalAssignmentId,
                );

            expect(result).toMatchObject({
                ok:
                    false,
                kind:
                    'response',
                status:
                    403,
                error: {
                    code:
                        'ORGANIZATIONAL_CONTEXT_REQUIRED',
                },
            });
        });

        it('preserves denied organizational context for stale Workspace recovery', async () => {
            apiMockServer.use(
                http.get(
                    `${window.location.origin}/api/v1/core/authorization/workspace-capabilities`,
                    () =>
                        HttpResponse.json(
                            {
                                status:
                                    'error',
                                code:
                                    'ORGANIZATIONAL_CONTEXT_DENIED',
                                message:
                                    'Organizational context is denied.',
                            },
                            {
                                status:
                                    403,
                            },
                        ),
                ),
            );

            const result =
                await projectBrowserWorkspaceCapabilities(
                    createBrowserApiClient(),
                    membershipId,
                    organizationalAssignmentId,
                );

            expect(result).toMatchObject({
                ok:
                    false,
                kind:
                    'response',
                status:
                    403,
                error: {
                    code:
                        'ORGANIZATIONAL_CONTEXT_DENIED',
                },
            });
        });

        it('preserves invalid organizational assignment locator failures', async () => {
            apiMockServer.use(
                http.get(
                    `${window.location.origin}/api/v1/core/authorization/workspace-capabilities`,
                    () =>
                        HttpResponse.json(
                            {
                                status:
                                    'error',
                                code:
                                    'INVALID_ORGANIZATIONAL_ASSIGNMENT_ID',
                                message:
                                    'Organizational assignment identifier is invalid.',
                            },
                            {
                                status:
                                    422,
                            },
                        ),
                ),
            );

            const result =
                await projectBrowserWorkspaceCapabilities(
                    createBrowserApiClient(),
                    membershipId,
                    organizationalAssignmentId,
                );

            expect(result).toMatchObject({
                ok:
                    false,
                kind:
                    'response',
                status:
                    422,
                error: {
                    code:
                        'INVALID_ORGANIZATIONAL_ASSIGNMENT_ID',
                },
            });
        });

        it('propagates cancellation without dispatching capability projection', async () => {
            let requestWasDispatched =
                false;

            apiMockServer.use(
                http.get(
                    `${window.location.origin}/api/v1/core/authorization/workspace-capabilities`,
                    () => {
                        requestWasDispatched =
                            true;

                        return HttpResponse.json(
                            workspaceCapabilitySuccess,
                        );
                    },
                ),
            );

            const controller =
                new AbortController();

            controller.abort();

            const result =
                await projectBrowserWorkspaceCapabilities(
                    createBrowserApiClient(),
                    membershipId,
                    organizationalAssignmentId,
                    {
                        signal:
                            controller.signal,
                    },
                );

            expect(result.ok).toBe(
                false,
            );

            if (
                result.ok
            ) {
                throw new Error(
                    'Expected aborted capability projection.',
                );
            }

            expect(
                result.kind,
            ).toBe(
                'aborted',
            );

            expect(
                requestWasDispatched,
            ).toBe(false);
        });
    },
);
