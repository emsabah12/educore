import {
    describe,
    expect,
    it,
} from 'vitest';

import {
    createWorkspaceBootstrapLifecycleClassifier,
    createWorkspaceMembershipLifecycleObservation,
} from '@/app/workspace/lifecycle';
import type {
    CanonicalMembershipContext,
    MembershipContextState,
} from '@/platform/membership';

const membershipId =
    '018f3b6a-7c20-7abc-8def-1234567890ab';

const otherMembershipId =
    '018f3b6a-7c20-7bcd-8def-1234567890ab';

const tenantId =
    '018f3b6a-7c20-7cde-8def-1234567890ab';

const otherTenantId =
    '018f3b6a-7c20-7def-8def-1234567890ab';

const tenantSubdomain =
    'educore-school';

const otherTenantSubdomain =
    'educore-school-b';

const context:
    CanonicalMembershipContext = {
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
                'EduCore School',

            subdomain:
                tenantSubdomain,
        },
    };

const otherContext:
    CanonicalMembershipContext = {
        membership: {
            id:
                otherMembershipId,

            status:
                'ACTIVE',
        },

        tenant: {
            id:
                otherTenantId,

            name:
                'EduCore School B',

            subdomain:
                otherTenantSubdomain,
        },
    };

function readyMembershipState(
    canonicalContext:
        CanonicalMembershipContext,
    tenantSubdomain:
        string,
): MembershipContextState {
    return {
        status:
            'ready',

        memberships: [
            {
                membership_id:
                    canonicalContext
                        .membership
                        .id,

                membership_status:
                    canonicalContext
                        .membership
                        .status,

                tenant_id:
                    canonicalContext
                        .tenant
                        .id,

                tenant_name:
                    canonicalContext
                        .tenant
                        .name,

                tenant_subdomain:
                    tenantSubdomain,
            },
        ],

        context:
            canonicalContext,

        failure:
            null,
    };
}

describe(
    'Workspace bootstrap lifecycle classifier',
    () => {
        it('allows restoration for the first canonical context resolved by initial session bootstrap', () => {
            const classifier =
                createWorkspaceBootstrapLifecycleClassifier();

            expect(
                classifier.observe(
                    'unknown',
                    {
                        status:
                            'unresolved',
                    },
                ),
            ).toBeNull();

            const decision =
                classifier.observe(
                    'authenticated',
                    createWorkspaceMembershipLifecycleObservation(
                        readyMembershipState(
                        context,
                        tenantSubdomain,
                    )
                    ),
                );

            expect(
                decision,
            ).toEqual({
                contextIdentity:
                    JSON.stringify([
                        membershipId,
                        tenantId,
                    ]),

                restoreHint:
                    true,
            });
        });

        it('disables restoration after a fresh authentication lifecycle', () => {
            const classifier =
                createWorkspaceBootstrapLifecycleClassifier();

            classifier.observe(
                'anonymous',
                {
                    status:
                        'unresolved',
                },
            );

            classifier.observe(
                'authenticating',
                {
                    status:
                        'unresolved',
                },
            );

            classifier.observe(
                'identity-authenticated',
                {
                    status:
                        'discovering',
                },
            );

            const decision =
                classifier.observe(
                    'authenticated',
                    createWorkspaceMembershipLifecycleObservation(
                        readyMembershipState(
                            context,
                            tenantSubdomain,
                        ),
                    ),
                );

            expect(
                decision?.restoreHint,
            ).toBe(
                false,
            );
        });

        it('disables restoration for a canonical context committed after Membership switching', () => {
            const classifier =
                createWorkspaceBootstrapLifecycleClassifier();

            classifier.observe(
                'authenticated',
                createWorkspaceMembershipLifecycleObservation(
                    readyMembershipState(
                        context,
                        tenantSubdomain,
                    )
                ),
            );

            classifier.observe(
                'authenticated',
                {
                    status:
                        'switching',
                },
            );

            const decision =
                classifier.observe(
                    'authenticated',
                    createWorkspaceMembershipLifecycleObservation(
                        readyMembershipState(
                            otherContext,
                            otherTenantSubdomain,
                        ),
                    ),
                );

            expect(
                decision,
            ).toEqual({
                contextIdentity:
                    JSON.stringify([
                        otherMembershipId,
                        otherTenantId,
                    ]),

                restoreHint:
                    false,
            });
        });

        it('retains the same decision for repeated observation of one canonical context', () => {
            const classifier =
                createWorkspaceBootstrapLifecycleClassifier();

            classifier.observe(
                'authenticating',
                {
                    status:
                        'unresolved',
                },
            );

            const membership =
                createWorkspaceMembershipLifecycleObservation(
                    readyMembershipState(
                        context,
                        tenantSubdomain,
                    ),
                );

            const first =
                classifier.observe(
                    'authenticated',
                    membership,
                );

            const second =
                classifier.observe(
                    'authenticated',
                    membership,
                );

            expect(
                first,
            ).toEqual(
                second,
            );

            expect(
                second?.restoreHint,
            ).toBe(
                false,
            );
        });

        it('does not create a restoration decision before canonical Membership readiness', () => {
            const classifier =
                createWorkspaceBootstrapLifecycleClassifier();

            expect(
                classifier.observe(
                    'authenticated',
                    {
                        status:
                            'discovering',
                    },
                ),
            ).toBeNull();

            expect(
                classifier.observe(
                    'membership-context-required',
                    {
                        status:
                            'selection-required',
                    },
                ),
            ).toBeNull();

            expect(
                classifier.observe(
                    'identity-authenticated',
                    {
                        status:
                            'switching',
                    },
                ),
            ).toBeNull();
        });
    },
);
