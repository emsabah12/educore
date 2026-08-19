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
    createBrowserMembershipHeaderParams,
    createBrowserWorkspaceHeaderParams,
} from '@/platform/api';
import { apiMockServer } from '@/test/server';

const membershipId =
    '018f3b6a-7c20-7abc-8def-1234567890ab';

const organizationalAssignmentId =
    '018f3b6a-7c20-7def-9abc-1234567890ab';

describe('Browser API request context', () => {
    it('does not inject locator headers into membership discovery', async () => {
        let observedMembershipId:
            string | null = null;

        let observedOrganizationalAssignmentId:
            string | null = null;

        apiMockServer.use(
            http.get(
                `${window.location.origin}/api/v1/user/my-memberships`,
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

                    return HttpResponse.json({
                        status: 'success',
                        data: [],
                    });
                },
            ),
        );

        const client = createBrowserApiClient();

        const {
            error,
            response,
        } = await client.GET(
            '/api/v1/user/my-memberships',
        );

        expect(error).toBeUndefined();

        expect(response.status).toBe(200);

        expect(observedMembershipId).toBeNull();

        expect(
            observedOrganizationalAssignmentId,
        ).toBeNull();
    });

    it('sends only the explicit membership locator for workspace discovery', async () => {
        let observedMembershipId:
            string | null = null;

        let observedOrganizationalAssignmentId:
            string | null = null;

        apiMockServer.use(
            http.get(
                `${window.location.origin}/api/v1/user/my-workspaces`,
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

                    return HttpResponse.json({
                        status: 'success',
                        data: [],
                    });
                },
            ),
        );

        const client = createBrowserApiClient();

        const {
            error,
            response,
        } = await client.GET(
            '/api/v1/user/my-workspaces',
            {
                params: {
                    header:
                        createBrowserMembershipHeaderParams({
                            membershipId,
                        }),
                },
            },
        );

        expect(error).toBeUndefined();

        expect(response.status).toBe(200);

        expect(observedMembershipId).toBe(
            membershipId,
        );

        expect(
            observedOrganizationalAssignmentId,
        ).toBeNull();
    });

    it('sends both explicit locators for workspace capabilities', async () => {
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

                    return HttpResponse.json({
                        status: 'success',
                        data: {},
                    });
                },
            ),
        );

        const client = createBrowserApiClient();

        const {
            error,
            response,
        } = await client.GET(
            '/api/v1/core/authorization/workspace-capabilities',
            {
                params: {
                    header:
                        createBrowserWorkspaceHeaderParams({
                            membershipId,
                            organizationalAssignmentId,
                        }),
                },
            },
        );

        expect(error).toBeUndefined();

        expect(response.status).toBe(200);

        expect(observedMembershipId).toBe(
            membershipId,
        );

        expect(
            observedOrganizationalAssignmentId,
        ).toBe(
            organizationalAssignmentId,
        );
    });

    it('propagates an aborted signal without dispatching the request', async () => {
        let requestWasDispatched = false;

        apiMockServer.use(
            http.get(
                `${window.location.origin}/api/v1/user/my-memberships`,
                () => {
                    requestWasDispatched = true;

                    return HttpResponse.json({
                        status: 'success',
                        data: [],
                    });
                },
            ),
        );

        const controller =
            new AbortController();

        controller.abort();

        const client = createBrowserApiClient();

        await expect(
            client.GET(
                '/api/v1/user/my-memberships',
                {
                    signal: controller.signal,
                },
            ),
        ).rejects.toMatchObject({
            name: 'AbortError',
        });

        expect(
            requestWasDispatched,
        ).toBe(false);
    });
});
