import {
    describe,
    expect,
    it,
} from 'vitest';

import type {
    BrowserApiFailure,
} from '@/platform/api';
import {
    createInitialMembershipContextState,
    membershipContextReducer,
    type CanonicalMembershipContext,
    type MembershipSummary,
} from '@/platform/membership';

const membershipAId =
    '018f3b6a-7c20-7abc-8def-1234567890ab';

const membershipBId =
    '018f3b6a-7c20-7bcd-8def-1234567890ab';

const tenantAId =
    '018f3b6a-7c20-7cde-8def-1234567890ab';

const tenantBId =
    '018f3b6a-7c20-7def-8abc-1234567890ab';

const memberships:
    readonly MembershipSummary[] = [
        {
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
    ];

const contextA:
    CanonicalMembershipContext = {
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
    };

const contextB:
    CanonicalMembershipContext = {
        membership: {
            id:
                membershipBId,
            status:
                'ACTIVE',
        },

        tenant: {
            id:
                tenantBId,
            name:
                'EduCore School B',
            subdomain:
                'school-b',
        },
    };

const networkFailure:
    BrowserApiFailure = {
        ok: false,
        kind:
            'network',
        cause:
            new TypeError(
                'Network unavailable',
            ),
    };

describe(
    'MembershipContext state',
    () => {
        it('starts unresolved', () => {
            expect(
                createInitialMembershipContextState(),
            ).toEqual({
                status:
                    'unresolved',
            });
        });

        it('resolves discovered memberships against an existing canonical context', () => {
            const discovering =
                membershipContextReducer(
                    createInitialMembershipContextState(),
                    {
                        type:
                            'DISCOVERY_STARTED',
                        context:
                            contextA,
                    },
                );

            const ready =
                membershipContextReducer(
                    discovering,
                    {
                        type:
                            'DISCOVERY_READY',
                        memberships,
                    },
                );

            expect(ready).toEqual({
                status:
                    'ready',
                memberships,
                context:
                    contextA,
                failure:
                    null,
            });
        });

        it('requires explicit selection when memberships exist without canonical context', () => {
            const discovering =
                membershipContextReducer(
                    createInitialMembershipContextState(),
                    {
                        type:
                            'DISCOVERY_STARTED',
                        context:
                            null,
                    },
                );

            const state =
                membershipContextReducer(
                    discovering,
                    {
                        type:
                            'DISCOVERY_READY',
                        memberships,
                    },
                );

            expect(state).toEqual({
                status:
                    'selection-required',
                memberships,
                failure:
                    null,
            });
        });

        it('supports an authenticated Person with no active memberships', () => {
            const discovering =
                membershipContextReducer(
                    createInitialMembershipContextState(),
                    {
                        type:
                            'DISCOVERY_STARTED',
                        context:
                            null,
                    },
                );

            const state =
                membershipContextReducer(
                    discovering,
                    {
                        type:
                            'DISCOVERY_EMPTY',
                    },
                );

            expect(state).toEqual({
                status:
                    'empty',
            });
        });

        it('fails closed when discovery is empty but canonical context already exists', () => {
            const discovering =
                membershipContextReducer(
                    createInitialMembershipContextState(),
                    {
                        type:
                            'DISCOVERY_STARTED',
                        context:
                            contextA,
                    },
                );

            expect(
                () =>
                    membershipContextReducer(
                        discovering,
                        {
                            type:
                                'DISCOVERY_EMPTY',
                        },
                    ),
            ).toThrow(
                'EduCore MembershipContext cannot become empty while canonical Membership/Tenant context exists.',
            );
        });

        it('fails closed when discovery does not contain the canonical Membership and Tenant pair', () => {
            const discovering =
                membershipContextReducer(
                    createInitialMembershipContextState(),
                    {
                        type:
                            'DISCOVERY_STARTED',
                        context: {
                            membership:
                                contextA.membership,
                            tenant:
                                contextB.tenant,
                        },
                    },
                );

            expect(
                () =>
                    membershipContextReducer(
                        discovering,
                        {
                            type:
                                'DISCOVERY_READY',
                            memberships,
                        },
                    ),
            ).toThrow(
                'EduCore MembershipContext discovery does not contain the canonical Membership/Tenant context.',
            );
        });

        it('keeps the current canonical context unchanged while another membership is switching', () => {
            const discovering =
                membershipContextReducer(
                    createInitialMembershipContextState(),
                    {
                        type:
                            'DISCOVERY_STARTED',
                        context:
                            contextA,
                    },
                );

            const ready =
                membershipContextReducer(
                    discovering,
                    {
                        type:
                            'DISCOVERY_READY',
                        memberships,
                    },
                );

            const switching =
                membershipContextReducer(
                    ready,
                    {
                        type:
                            'SWITCH_STARTED',
                        membershipId:
                            membershipBId,
                    },
                );

            expect(switching).toEqual({
                status:
                    'switching',
                memberships,
                context:
                    contextA,
                target:
                    memberships[1],
            });
        });

        it('commits a switch only after canonical context confirmation matches the target', () => {
            const discovering =
                membershipContextReducer(
                    createInitialMembershipContextState(),
                    {
                        type:
                            'DISCOVERY_STARTED',
                        context:
                            contextA,
                    },
                );

            const ready =
                membershipContextReducer(
                    discovering,
                    {
                        type:
                            'DISCOVERY_READY',
                        memberships,
                    },
                );

            const switching =
                membershipContextReducer(
                    ready,
                    {
                        type:
                            'SWITCH_STARTED',
                        membershipId:
                            membershipBId,
                    },
                );

            const confirmed =
                membershipContextReducer(
                    switching,
                    {
                        type:
                            'CONTEXT_CONFIRMED',
                        context:
                            contextB,
                    },
                );

            expect(confirmed).toEqual({
                status:
                    'ready',
                memberships,
                context:
                    contextB,
                failure:
                    null,
            });
        });

        it('does not commit canonical context for the wrong switch target', () => {
            const discovering =
                membershipContextReducer(
                    createInitialMembershipContextState(),
                    {
                        type:
                            'DISCOVERY_STARTED',
                        context:
                            contextA,
                    },
                );

            const ready =
                membershipContextReducer(
                    discovering,
                    {
                        type:
                            'DISCOVERY_READY',
                        memberships,
                    },
                );

            const switching =
                membershipContextReducer(
                    ready,
                    {
                        type:
                            'SWITCH_STARTED',
                        membershipId:
                            membershipBId,
                    },
                );

            expect(
                () =>
                    membershipContextReducer(
                        switching,
                        {
                            type:
                                'CONTEXT_CONFIRMED',
                            context:
                                contextA,
                        },
                    ),
            ).toThrow(
                'EduCore MembershipContext canonical confirmation does not match the requested switch target.',
            );
        });

        it('restores the previous stable context when switching fails', () => {
            const discovering =
                membershipContextReducer(
                    createInitialMembershipContextState(),
                    {
                        type:
                            'DISCOVERY_STARTED',
                        context:
                            contextA,
                    },
                );

            const ready =
                membershipContextReducer(
                    discovering,
                    {
                        type:
                            'DISCOVERY_READY',
                        memberships,
                    },
                );

            const switching =
                membershipContextReducer(
                    ready,
                    {
                        type:
                            'SWITCH_STARTED',
                        membershipId:
                            membershipBId,
                    },
                );

            const restored =
                membershipContextReducer(
                    switching,
                    {
                        type:
                            'SWITCH_FAILED',
                        failure:
                            networkFailure,
                    },
                );

            expect(restored).toEqual({
                status:
                    'ready',
                memberships,
                context:
                    contextA,
                failure:
                    networkFailure,
            });
        });
    },
);
