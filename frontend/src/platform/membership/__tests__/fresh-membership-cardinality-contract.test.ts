import {
    describe,
    expect,
    it,
    vi,
} from 'vitest';

import type {
    BrowserApiResult,
} from '@/platform/api';

import type {
    BrowserAuthState,
} from '@/platform/auth';

import {
    createMembershipContextRuntime,
    type BrowserMembershipSwitchSuccess,
    type MembershipAuthenticationRuntime,
    type MembershipContextOperations,
    type MembershipListSuccess,
    type MembershipSummary,
} from '@/platform/membership';

const userId =
    '018f3b6a-7c20-7aaa-8def-1234567890ab';

const personId =
    '018f3b6a-7c20-7aab-8def-1234567890ab';

const membershipAId =
    '018f3b6a-7c20-7abc-8def-1234567890ab';

const membershipBId =
    '018f3b6a-7c20-7bcd-8def-1234567890ab';

const tenantAId =
    '018f3b6a-7c20-7cde-8def-1234567890ab';

const tenantBId =
    '018f3b6a-7c20-7def-8abc-1234567890ab';

const membershipA:
    MembershipSummary = {
        membership_id:
            membershipAId,

        membership_status:
            'ACTIVE',

        tenant_id:
            tenantAId,

        tenant_name:
            'EduCore School A',

        tenant_subdomain:
            'school-a',
    };

const membershipB:
    MembershipSummary = {
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
    };

const freshIdentityState:
    BrowserAuthState = {
        status:
            'identity-authenticated',

        identity: {
            context_type:
                'identity',

            user: {
                id:
                    userId,

                name:
                    'EduCore User',

                email:
                    'user@example.test',

                username:
                    'user',
            },

            platform: {
                is_superadmin:
                    false,
            },
        },
    };

const authenticatedA:
    BrowserAuthState = {
        status:
            'authenticated',

        identity: {
            user: {
                id:
                    userId,

                email:
                    'user@example.test',
            },

            person: {
                id:
                    personId,

                name:
                    'EduCore User',
            },

            membership: {
                id:
                    membershipAId,

                status:
                    'ACTIVE',
            },

            tenant: {
                id:
                    tenantAId,

                name:
                    'EduCore School A',

                subdomain:
                    'school-a',
            },
        },
    };

const switchSuccessA:
    BrowserApiResult<
        BrowserMembershipSwitchSuccess
    > = {
        ok:
            true,

        status:
            200,

        data: {
            status:
                'success',

            data: {
                membership_id:
                    membershipAId,

                tenant_id:
                    tenantAId,

                tenant_name:
                    'EduCore School A',
            },
        },
    };

function discoveryResult(
    memberships:
        readonly MembershipSummary[],
): BrowserApiResult<
    MembershipListSuccess
> {
    return {
        ok:
            true,

        status:
            200,

        data: {
            status:
                'success',

            data:
                [...memberships],
        },
    };
}

function createAuthentication(
    bootstrap:
        MembershipAuthenticationRuntime['bootstrap'],
): MembershipAuthenticationRuntime {
    return {
        getState() {
            return freshIdentityState;
        },

        bootstrap,

        observeFailure() {
            return freshIdentityState;
        },
    };
}

describe(
    'fresh identity Membership cardinality orchestration',
    () => {
        it('keeps zero Memberships empty without attempting a switch', async () => {
            const switchMembership =
                vi.fn(
                    async () =>
                        switchSuccessA,
                );

            const bootstrapAuthentication =
                vi.fn(
                    async () =>
                        authenticatedA,
                );

            const operations =
                {
                    async discover() {
                        return discoveryResult(
                            [],
                        );
                    },

                    switchMembership,
                } satisfies MembershipContextOperations;

            const runtime =
                createMembershipContextRuntime(
                    operations,
                    createAuthentication(
                        bootstrapAuthentication,
                    ),
                );

            const state =
                await runtime.bootstrap({
                    restoreHint:
                        false,
                });

            expect(
                state,
            ).toEqual({
                status:
                    'empty',
            });

            expect(
                switchMembership,
            ).not.toHaveBeenCalled();

            expect(
                bootstrapAuthentication,
            ).not.toHaveBeenCalled();
        });

        it('auto-selects exactly one Membership through canonical switch and authentication verification', async () => {
            const switchMembership =
                vi.fn(
                    async () =>
                        switchSuccessA,
                );

            const bootstrapAuthentication =
                vi.fn(
                    async () =>
                        authenticatedA,
                );

            const operations =
                {
                    async discover() {
                        return discoveryResult([
                            membershipA,
                        ]);
                    },

                    switchMembership,
                } satisfies MembershipContextOperations;

            const runtime =
                createMembershipContextRuntime(
                    operations,
                    createAuthentication(
                        bootstrapAuthentication,
                    ),
                );

            const state =
                await runtime.bootstrap({
                    restoreHint:
                        false,
                });

            /*
             * Exactly-one auto-selection must reuse the
             * canonical Browser Membership switch operation.
             *
             * Discovery itself is never allowed to invent
             * Tenant authority.
             */
            expect(
                switchMembership,
            ).toHaveBeenCalledTimes(
                1,
            );

            expect(
                switchMembership,
            ).toHaveBeenCalledWith(
                membershipAId,
                {},
            );

            /*
             * Browser switch only prepares server-side
             * credential custody.
             *
             * Canonical /auth/me confirmation must still
             * verify the selected Membership/Tenant pair
             * before local state becomes READY.
             */
            expect(
                bootstrapAuthentication,
            ).toHaveBeenCalledTimes(
                1,
            );

            expect(
                bootstrapAuthentication,
            ).toHaveBeenCalledWith({
                membershipId:
                    membershipAId,
            });

            expect(
                state,
            ).toEqual({
                status:
                    'ready',

                memberships: [
                    membershipA,
                ],

                context: {
                    membership:
                        authenticatedA.identity.membership,

                    tenant:
                        authenticatedA.identity.tenant,
                },

                failure:
                    null,
            });
        });

        it('requires explicit selection for multiple Memberships and never auto-switches', async () => {
            const switchMembership =
                vi.fn(
                    async () =>
                        switchSuccessA,
                );

            const bootstrapAuthentication =
                vi.fn(
                    async () =>
                        authenticatedA,
                );

            const operations =
                {
                    async discover() {
                        return discoveryResult([
                            membershipA,
                            membershipB,
                        ]);
                    },

                    switchMembership,
                } satisfies MembershipContextOperations;

            const runtime =
                createMembershipContextRuntime(
                    operations,
                    createAuthentication(
                        bootstrapAuthentication,
                    ),
                );

            const state =
                await runtime.bootstrap({
                    restoreHint:
                        false,
                });

            expect(
                state,
            ).toEqual({
                status:
                    'selection-required',

                memberships: [
                    membershipA,
                    membershipB,
                ],

                failure:
                    null,
            });

            expect(
                switchMembership,
            ).not.toHaveBeenCalled();

            expect(
                bootstrapAuthentication,
            ).not.toHaveBeenCalled();
        });
    },
);
