import {
    afterEach,
    describe,
    expect,
    it,
} from 'vitest';

import type {
    CanonicalMembershipContext,
} from '@/platform/membership';
import {
    clearBrowserMembershipRestorationHint,
    persistBrowserMembershipRestorationHint,
    readBrowserMembershipRestorationHint,
} from '@/platform/membership/restoration';

const membershipId =
    '018f3b6a-7c20-7abc-8def-1234567890ab';

const tenantId =
    '018f3b6a-7c20-7cde-8def-1234567890ab';

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
                'educore-school',
        },
    };

afterEach(
    () => {
        window.sessionStorage.clear();
    },
);

describe(
    'Membership restoration hint',
    () => {
        it(
            'persists only the canonical Membership and Tenant locator pair in sessionStorage',
            () => {
                expect(
                    persistBrowserMembershipRestorationHint(
                        context,
                    ),
                ).toBe(
                    true,
                );

                expect(
                    readBrowserMembershipRestorationHint(),
                ).toEqual({
                    membership_id:
                        membershipId,

                    tenant_id:
                        tenantId,
                });

                const serialized =
                    JSON.stringify(
                        Object.fromEntries(
                            Object.entries(
                                window.sessionStorage,
                            ),
                        ),
                    );

                expect(
                    serialized,
                ).not.toContain(
                    'access_token',
                );

                expect(
                    serialized,
                ).not.toMatch(
                    /Bearer\s+/iu,
                );
            },
        );

        it(
            'clears the restoration hint explicitly',
            () => {
                persistBrowserMembershipRestorationHint(
                    context,
                );

                expect(
                    clearBrowserMembershipRestorationHint(),
                ).toBe(
                    true,
                );

                expect(
                    readBrowserMembershipRestorationHint(),
                ).toBeNull();
            },
        );

        it(
            'rejects and removes malformed client-owned restoration state',
            () => {
                window.sessionStorage.setItem(
                    'educore.membership-restoration.v1',
                    JSON.stringify({
                        membership_id:
                            '',
                        tenant_id:
                            tenantId,
                    }),
                );

                expect(
                    readBrowserMembershipRestorationHint(),
                ).toBeNull();

                expect(
                    window.sessionStorage.getItem(
                        'educore.membership-restoration.v1',
                    ),
                ).toBeNull();
            },
        );

        it(
            'rejects and removes non-json client-owned restoration state',
            () => {
                window.sessionStorage.setItem(
                    'educore.membership-restoration.v1',
                    '{invalid-json',
                );

                expect(
                    readBrowserMembershipRestorationHint(),
                ).toBeNull();

                expect(
                    window.sessionStorage.getItem(
                        'educore.membership-restoration.v1',
                    ),
                ).toBeNull();
            },
        );
    },
);
