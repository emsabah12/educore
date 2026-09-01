import {
    render,
    waitFor,
} from '@testing-library/react';

import {
    describe,
    expect,
    it,
    vi,
} from 'vitest';

import {
    BrowserAuthProvider,
} from '@/app/auth/BrowserAuthProvider';

import {
    MembershipContextProvider,
} from '@/app/membership/MembershipContextProvider';

import type {
    BrowserApiResult,
} from '@/platform/api';

import type {
    BrowserAuthRuntime,
    BrowserAuthState,
    BrowserLoginData,
} from '@/platform/auth';

import {
    createMembershipContextRuntime,
    type MembershipAuthenticationRuntime,
    type MembershipContextOperations,
    type MembershipContextRuntime,
    type MembershipContextState,
    type MembershipListSuccess,
} from '@/platform/membership';

const membershipId =
    '018f3b6a-7c20-7abc-8def-1234567890ab';

const tenantId =
    '018f3b6a-7c20-7cde-8def-1234567890ab';

const membershipBId =
    '018f3b6a-7c20-7bcd-8def-1234567890ab';

const tenantBId =
    '018f3b6a-7c20-7def-8abc-1234567890ab';

const globalIdentity:
    BrowserLoginData = {
        context_type:
            'identity',

        user: {
            id:
                '018f3b6a-7c20-7def-8abc-1234567890ab',

            name:
                'EduCore Member',

            email:
                'member@example.com',

            username:
                'member',
        },

        platform: {
            is_superadmin:
                false,
        },
    };

const identityAuthenticatedState:
    BrowserAuthState = {
        status:
            'identity-authenticated',

        identity:
            globalIdentity,
    };

const discoverySuccess:
    BrowserApiResult<
        MembershipListSuccess
    > = {
        ok:
            true,

        status:
            200,

        data: {
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

                {
                    membership_id:
                        membershipBId,

                    membership_status:
                        'ACTIVE',

                    tenant_id:
                        tenantBId,

                    tenant_name:
                        'EduCore School B',

                    tenant_subdomain:
                        'school-b',
                },
            ],
        },
    };

function createIdentityAuthentication():
    MembershipAuthenticationRuntime {
    return {
        getState() {
            return identityAuthenticatedState;
        },

        async bootstrap() {
            throw new Error(
                'Fresh Membership discovery must not resolve Tenant context.',
            );
        },

        observeFailure() {
            return identityAuthenticatedState;
        },
    };
}

describe(
    'fresh identity Membership bootstrap contract',
    () => {
        it('allows identity-authenticated User context to discover Memberships without Tenant context', async () => {
            const discover =
                vi.fn(
                    async () =>
                        discoverySuccess,
                );

            const operations:
                MembershipContextOperations = {
                discover,

                async switchMembership() {
                    throw new Error(
                        'Fresh discovery must not switch Membership automatically at this boundary.',
                    );
                },
            };

            const runtime =
                createMembershipContextRuntime(
                    operations,
                    createIdentityAuthentication(),
                );

            const state =
                await runtime.bootstrap({
                    restoreHint:
                        false,
                });

            expect(
                discover,
            ).toHaveBeenCalledTimes(
                1,
            );

            expect(
                state,
            ).toEqual({
                status:
                    'selection-required',

                memberships:
                    discoverySuccess
                        .data
                        ?.data,

                failure:
                    null,
            });
        });

        it('starts Membership bootstrap for fresh identity with restoration explicitly disabled', async () => {
            const authentication:
                BrowserAuthRuntime = {
                getState() {
                    return identityAuthenticatedState;
                },

                subscribe() {
                    return () => {};
                },

                async bootstrap() {
                    return identityAuthenticatedState;
                },

                async login() {
                    return identityAuthenticatedState;
                },

                async logout() {
                    return identityAuthenticatedState;
                },

                observeFailure() {
                    return identityAuthenticatedState;
                },
            };

            const unresolvedState:
                MembershipContextState = {
                status:
                    'unresolved',
            };

            const selectionRequiredState:
                MembershipContextState = {
                status:
                    'selection-required',

                memberships:
                    discoverySuccess
                        .data
                        ?.data
                        ?? [],

                failure:
                    null,
            };

            const bootstrapMembership =
                vi.fn(
                    async () =>
                        selectionRequiredState,
                );

            const membership:
                MembershipContextRuntime = {
                getState() {
                    return unresolvedState;
                },

                subscribe() {
                    return () => {};
                },

                bootstrap:
                    bootstrapMembership,

                async switchMembership() {
                    throw new Error(
                        'Unexpected Membership switch.',
                    );
                },

                reset() {
                    return unresolvedState;
                },
            };

            render(
                <BrowserAuthProvider
                    runtime={
                        authentication
                    }
                >
                    <MembershipContextProvider
                        runtime={
                            membership
                        }
                    >
                        <div>
                            fresh identity
                        </div>
                    </MembershipContextProvider>
                </BrowserAuthProvider>,
            );

            await waitFor(() => {
                expect(
                    bootstrapMembership,
                ).toHaveBeenCalledTimes(
                    1,
                );
            });

            expect(
                bootstrapMembership,
            ).toHaveBeenCalledWith({
                signal:
                    expect.any(
                        AbortSignal,
                    ),

                restoreHint:
                    false,
            });
        });
    },
);
