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

            apiMockServer.use(
                http.get(
                    `${window.location.origin}/api/v1/auth/me`,
                    () => {
                        authRequestCount +=
                            1;

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
                                status: 403,
                            },
                        );
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
                membershipRequestCount,
            ).toBe(0);

            const membershipState =
                await runtime.membership
                    .bootstrap();

            expect(
                membershipRequestCount,
            ).toBe(1);

            expect(
                membershipState,
            ).toEqual({
                status:
                    'selection-required',
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
                failure:
                    null,
            });
        });
    },
);
