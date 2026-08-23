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
    discoverBrowserWorkspaces,
    type WorkspaceDiscoverySuccess,
} from '@/platform/workspace';
import { apiMockServer } from '@/test/server';

const membershipId =
    '018f3b6a-7c20-7abc-8def-1234567890ab';

const tenantId =
    '018f3b6a-7c20-7cde-8def-1234567890ab';

const organizationAssignmentId =
    '018f3b6a-7c20-7def-8abc-1234567890ab';

const organizationId =
    '018f3b6a-7c20-7abc-9def-1234567890ab';

const unitAssignmentId =
    '018f3b6a-7c20-7bcd-9abc-1234567890ab';

const organizationUnitId =
    '018f3b6a-7c20-7cde-9abc-1234567890ab';

const workspaceDiscoveryResponse:
    WorkspaceDiscoverySuccess = {
        status:
            'success',

        data: {
            tenant: {
                id:
                    tenantId,
                name:
                    'EduCore School',
            },

            workspaces: [
                {
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
                },
                {
                    type:
                        'ORGANIZATION',
                    organizational_assignment_id:
                        organizationAssignmentId,
                    organization_id:
                        organizationId,
                    organization_unit_id:
                        null,
                    label:
                        'SMA EduCore',
                },
                {
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
                },
            ],
        },
    };

describe(
    'discoverBrowserWorkspaces',
    () => {
        it('discovers canonical Workspaces with only the explicit Membership locator', async () => {
            let observedMembershipLocator:
                string | null =
                    null;

            let observedOrganizationalLocator:
                string | null =
                    'NOT_CAPTURED';

            apiMockServer.use(
                http.get(
                    `${window.location.origin}/api/v1/user/my-workspaces`,
                    ({
                        request,
                    }) => {
                        observedMembershipLocator =
                            request.headers.get(
                                'X-EduCore-Membership-Id',
                            );

                        observedOrganizationalLocator =
                            request.headers.get(
                                'X-EduCore-Organizational-Assignment-Id',
                            );

                        return HttpResponse.json(
                            workspaceDiscoveryResponse,
                        );
                    },
                ),
            );

            const client =
                createBrowserApiClient();

            const result =
                await discoverBrowserWorkspaces(
                    client,
                    membershipId,
                );

            expect(
                observedMembershipLocator,
            ).toBe(
                membershipId,
            );

            expect(
                observedOrganizationalLocator,
            ).toBeNull();

            expect(result).toEqual({
                ok: true,
                status: 200,
                data:
                    workspaceDiscoveryResponse,
            });
        });

        it('retries transient network failure while preserving the Membership locator', async () => {
            let attempts =
                0;

            const observedMembershipLocators:
                Array<string | null> = [];

            const observedOrganizationalLocators:
                Array<string | null> = [];

            apiMockServer.use(
                http.get(
                    `${window.location.origin}/api/v1/user/my-workspaces`,
                    ({
                        request,
                    }) => {
                        attempts +=
                            1;

                        observedMembershipLocators.push(
                            request.headers.get(
                                'X-EduCore-Membership-Id',
                            ),
                        );

                        observedOrganizationalLocators.push(
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
                            workspaceDiscoveryResponse,
                        );
                    },
                ),
            );

            const client =
                createBrowserApiClient();

            const result =
                await discoverBrowserWorkspaces(
                    client,
                    membershipId,
                );

            expect(attempts).toBe(2);

            expect(
                observedMembershipLocators,
            ).toEqual([
                membershipId,
                membershipId,
            ]);

            expect(
                observedOrganizationalLocators,
            ).toEqual([
                null,
                null,
            ]);

            expect(result).toEqual({
                ok: true,
                status: 200,
                data:
                    workspaceDiscoveryResponse,
            });
        });

        it('preserves BrowserSession authentication-required failures', async () => {
            apiMockServer.use(
                http.get(
                    `${window.location.origin}/api/v1/user/my-workspaces`,
                    () => HttpResponse.json(
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

            const client =
                createBrowserApiClient();

            const result =
                await discoverBrowserWorkspaces(
                    client,
                    membershipId,
                );

            expect(result).toEqual({
                ok: false,
                kind:
                    'response',
                status: 401,
                error: {
                    status:
                        'error',
                    code:
                        'BROWSER_SESSION_AUTHENTICATION_REQUIRED',
                    message:
                        'Authenticated browser session is required.',
                },
            });
        });

        it('preserves unavailable Browser Membership context failures', async () => {
            apiMockServer.use(
                http.get(
                    `${window.location.origin}/api/v1/user/my-workspaces`,
                    () => HttpResponse.json(
                        {
                            status:
                                'error',
                            code:
                                'BROWSER_MEMBERSHIP_CONTEXT_DENIED',
                            message:
                                'Browser membership context is not available in this session.',
                        },
                        {
                            status:
                                403,
                        },
                    ),
                ),
            );

            const client =
                createBrowserApiClient();

            const result =
                await discoverBrowserWorkspaces(
                    client,
                    membershipId,
                );

            expect(result).toEqual({
                ok: false,
                kind:
                    'response',
                status: 403,
                error: {
                    status:
                        'error',
                    code:
                        'BROWSER_MEMBERSHIP_CONTEXT_DENIED',
                    message:
                        'Browser membership context is not available in this session.',
                },
            });
        });

        it('preserves invalid Browser Membership locator failures', async () => {
            apiMockServer.use(
                http.get(
                    `${window.location.origin}/api/v1/user/my-workspaces`,
                    () => HttpResponse.json(
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

            const client =
                createBrowserApiClient();

            const result =
                await discoverBrowserWorkspaces(
                    client,
                    membershipId,
                );

            expect(result).toMatchObject({
                ok: false,
                kind:
                    'response',
                status: 422,
                error: {
                    status:
                        'error',
                    code:
                        'INVALID_BROWSER_MEMBERSHIP_ID',
                },
            });
        });

        it('propagates cancellation without dispatching Workspace discovery', async () => {
            let requestWasDispatched =
                false;

            apiMockServer.use(
                http.get(
                    `${window.location.origin}/api/v1/user/my-workspaces`,
                    () => {
                        requestWasDispatched =
                            true;

                        return HttpResponse.json(
                            workspaceDiscoveryResponse,
                        );
                    },
                ),
            );

            const controller =
                new AbortController();

            controller.abort();

            const client =
                createBrowserApiClient();

            const result =
                await discoverBrowserWorkspaces(
                    client,
                    membershipId,
                    {
                        signal:
                            controller.signal,
                    },
                );

            expect(
                requestWasDispatched,
            ).toBe(false);

            expect(result.ok).toBe(
                false,
            );

            if (result.ok) {
                throw new Error(
                    'Expected aborted Workspace discovery.',
                );
            }

            expect(result.kind).toBe(
                'aborted',
            );
        });
    },
);
