import {
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import {
    http,
    HttpResponse,
} from 'msw';
import {
    afterEach,
    describe,
    expect,
    it,
} from 'vitest';

import {
    AppBootstrap,
} from '@/app/AppBootstrap';
import {
    createApplicationRuntime,
} from '@/app/runtime';
import {
    apiMockServer,
} from '@/test/server';

const membershipId =
    '018f3b6a-7c20-7abc-8def-1234567890ab';

const tenantId =
    '018f3b6a-7c20-7cde-8def-1234567890ab';

afterEach(() => {
    window.history.replaceState(
        null,
        '',
        '/',
    );
});

describe(
    'AppBootstrap',
    () => {
        it('mounts the composed providers and resolves initial Membership selection truth', async () => {
            window.history.replaceState(
                null,
                '',
                '/',
            );

            let membershipRequestCount =
                0;

            apiMockServer.use(
                http.get(
                    `${window.location.origin}/api/v1/auth/me`,
                    () => HttpResponse.json(
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
                    ),
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
                                        'EduCore School',
                                    tenant_subdomain:
                                        'school',
                                },
                            ],
                        });
                    },
                ),
            );

            const runtime =
                createApplicationRuntime();

            render(
                <AppBootstrap
                    runtime={runtime}
                />,
            );

            expect(
                await screen.findByRole(
                    'heading',
                    {
                        name:
                            'Frontend Foundation',
                    },
                ),
            ).toBeInTheDocument();

            await waitFor(() => {
                expect(
                    runtime.auth
                        .getState()
                        .status,
                ).toBe(
                    'membership-context-required',
                );
            });

            await waitFor(() => {
                expect(
                    runtime.membership
                        .getState()
                        .status,
                ).toBe(
                    'selection-required',
                );
            });

            expect(
                membershipRequestCount,
            ).toBe(1);
        });
    },
);
