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
    createApplicationRuntime,
} from '@/app/runtime';
import { apiMockServer } from '@/test/server';

const membershipId =
    '018f3b6a-7c20-7abc-8def-1234567890ab';

const tenantId =
    '018f3b6a-7c20-7cde-8def-1234567890ab';

const userId =
    '018f3b6a-7c20-7def-8def-1234567890ab';

const personId =
    '018f3b6a-7c20-7eee-8def-1234567890ab';

describe(
    'Application Membership runtime',
    () => {
        it('composes an unresolved Membership runtime without dispatching network requests', async () => {
            let membershipRequestCount =
                0;

            apiMockServer.use(
                http.get(
                    `${window.location.origin}/api/v1/user/my-memberships`,
                    () => {
                        membershipRequestCount +=
                            1;

                        return HttpResponse.json({
                            status:
                                'success',
                            data: [],
                        });
                    },
                ),
            );

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
                membershipRequestCount,
            ).toBe(0);

            const state =
                await runtime.membership
                    .bootstrap();

            expect(state).toEqual({
                status:
                    'unresolved',
            });

            expect(
                membershipRequestCount,
            ).toBe(0);
        });

        it('shares canonical authentication truth with Membership discovery', async () => {
            let authRequestCount =
                0;

            let membershipRequestCount =
                0;

            let switchRequestCount =
                0;

            const observedMembershipIds:
                Array<string | null> = [];

            apiMockServer.use(
                http.get(
                    `${window.location.origin}/api/v1/auth/me`,
                    ({
                        request,
                    }) => {
                        authRequestCount +=
                            1;

                        observedMembershipIds.push(
                            request.headers.get(
                                'X-EduCore-Membership-Id',
                            ),
                        );

                        if (
                            authRequestCount
                                === 1
                        ) {
                            return HttpResponse.json(
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
                            );
                        }

                        return HttpResponse.json({
                            status:
                                'success',

                            data: {
                                user: {
                                    id:
                                        userId,

                                    email:
                                        'member@example.com',
                                },

                                person: {
                                    id:
                                        personId,

                                    name:
                                        'EduCore Member',
                                },

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
                                        'EduCore School A',

                                    subdomain:
                                        'school-a',
                                },
                            },
                        });
                    },
                ),

                http.post(
                    `${window.location.origin}/api/v1/browser/user/memberships/${membershipId}/switch`,
                    () => {
                        switchRequestCount +=
                            1;

                        return HttpResponse.json({
                            status:
                                'success',

                            data: {
                                membership_id:
                                    membershipId,

                                tenant_id:
                                    tenantId,

                                tenant_name:
                                    'EduCore School A',
                            },
                        });
                    },
                ),

                http.get(
                    `${window.location.origin}/api/v1/user/my-memberships`,
                    () => {
                        membershipRequestCount +=
                            1;

                        return HttpResponse.json({
                            status:
                                'success',
                            data: [
                                {
                                    membership_id:
                                        membershipId,
                                    membership_status:
                                        'ACTIVE',
                                    tenant_id:
                                        tenantId,
                                    tenant_name:
                                        'EduCore School A',
                                    tenant_subdomain:
                                        'school-a',
                                },
                            ],
                        });
                    },
                ),
            );

            const runtime =
                createApplicationRuntime();

            expect(
                runtime.auth.getState(),
            ).toEqual({
                status:
                    'unknown',
            });

            expect(
                runtime.membership
                    .getState(),
            ).toEqual({
                status:
                    'unresolved',
            });

            const authState =
                await runtime.auth
                    .bootstrap();

            expect(authState).toEqual({
                status:
                    'membership-context-required',
                failure: {
                    ok: false,
                    kind:
                        'response',
                    status: 403,
                    error: {
                        status:
                            'error',
                        code:
                            'BROWSER_MEMBERSHIP_CONTEXT_REQUIRED',
                        message:
                            'Browser membership context is required.',
                    },
                },
            });

            expect(
                authRequestCount,
            ).toBe(1);

            expect(
                observedMembershipIds,
            ).toEqual([
                null,
            ]);

            expect(
                membershipRequestCount,
            ).toBe(0);

            expect(
                switchRequestCount,
            ).toBe(0);

            const membershipState =
                await runtime.membership
                    .bootstrap();

            expect(
                membershipRequestCount,
            ).toBe(1);

            expect(
                switchRequestCount,
            ).toBe(1);

            expect(
                authRequestCount,
            ).toBe(2);

            expect(
                observedMembershipIds,
            ).toEqual([
                null,
                membershipId,
            ]);

            expect(
                membershipState,
            ).toEqual({
                status:
                    'ready',

                memberships: [
                    {
                        membership_id:
                            membershipId,

                        membership_status:
                            'ACTIVE',

                        tenant_id:
                            tenantId,

                        tenant_name:
                            'EduCore School A',

                        tenant_subdomain:
                            'school-a',
                    },
                ],

                context: {
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
                            'EduCore School A',

                        subdomain:
                            'school-a',
                    },
                },

                failure:
                    null,
            });

            expect(
                runtime.auth.getState(),
            ).toEqual({
                status:
                    'authenticated',

                identity: {
                    user: {
                        id:
                            userId,

                        email:
                            'member@example.com',
                    },

                    person: {
                        id:
                            personId,

                        name:
                            'EduCore Member',
                    },

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
                            'EduCore School A',

                        subdomain:
                            'school-a',
                    },
                },
            });
        });
    },
);
