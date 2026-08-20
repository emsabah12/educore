import {
    describe,
    expect,
    it,
} from 'vitest';

import type {
    BrowserApiFailure,
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

const memberships:
    MembershipSummary[] = [
        membershipA,
        membershipB,
    ];

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

const authenticatedB:
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
        },
    };

const membershipRequiredFailure:
    BrowserApiFailure = {
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
    };

const sessionRequiredFailure:
    BrowserApiFailure = {
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
    };

const switchDeniedFailure:
    BrowserApiFailure = {
        ok: false,
        kind:
            'response',
        status: 403,

        error: {
            status:
                'error',
            code:
                'MEMBERSHIP_SWITCH_DENIED',
            message:
                'Requested membership is unavailable.',
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

const abortedFailure:
    BrowserApiFailure = {
        ok: false,
        kind:
            'aborted',
        cause:
            new Error(
                'Membership operation cancelled',
            ),
    };

const discoverySuccess:
    BrowserApiResult<
        MembershipListSuccess
    > = {
        ok: true,
        status: 200,

        data: {
            status:
                'success',
            data:
                memberships,
        },
    };

const switchSuccess:
    BrowserApiResult<
        BrowserMembershipSwitchSuccess
    > = {
        ok: true,
        status: 200,

        data: {
            status:
                'success',

            data: {
                membership_id:
                    membershipBId,
                tenant_id:
                    tenantBId,
                tenant_name:
                    'EduCore School B',
            },
        },
    };

function createOperations(
    overrides:
        Partial<
            MembershipContextOperations
        > = {},
): MembershipContextOperations {
    return {
        async discover() {
            return discoverySuccess;
        },

        async switchMembership() {
            return switchSuccess;
        },

        ...overrides,
    };
}

function createAuthenticationHarness(
    initialState:
        BrowserAuthState,
    bootstrapImplementation?:
        MembershipAuthenticationRuntime['bootstrap'],
) {
    let state =
        initialState;

    const observedFailures:
        BrowserApiFailure[] = [];

    const bootstrapMembershipIds:
        Array<
            string | null
        > = [];

    const runtime:
        MembershipAuthenticationRuntime = {
            getState() {
                return state;
            },

            async bootstrap(
                options,
            ) {
                bootstrapMembershipIds.push(
                    options?.membershipId
                        ?? null,
                );

                if (
                    bootstrapImplementation
                        !== undefined
                ) {
                    const next =
                        await bootstrapImplementation(
                            options,
                        );

                    state =
                        next;

                    return next;
                }

                return state;
            },

            observeFailure(
                failure,
            ) {
                observedFailures.push(
                    failure,
                );

                state = {
                    status:
                        'anonymous',
                    failure,
                };

                return state;
            },
        };

    return {
        runtime,

        getState() {
            return state;
        },

        observedFailures,
        bootstrapMembershipIds,
    };
}

describe(
    'MembershipContextRuntime',
    () => {
        it('discovers Memberships against an existing canonical authentication context', async () => {
            const authentication =
                createAuthenticationHarness(
                    authenticatedA,
                );

            const runtime =
                createMembershipContextRuntime(
                    createOperations(),
                    authentication.runtime,
                );

            const state =
                await runtime.bootstrap();

            expect(state).toEqual({
                status:
                    'ready',
                memberships,
                context: {
                    membership:
                        authenticatedA
                            .identity
                            .membership,
                    tenant:
                        authenticatedA
                            .identity
                            .tenant,
                },
                failure:
                    null,
            });
        });

        it('discovers Memberships for a BrowserSession that still requires explicit context selection', async () => {
            const authentication =
                createAuthenticationHarness({
                    status:
                        'membership-context-required',
                    failure:
                        membershipRequiredFailure,
                });

            const runtime =
                createMembershipContextRuntime(
                    createOperations(),
                    authentication.runtime,
                );

            const state =
                await runtime.bootstrap();

            expect(state).toEqual({
                status:
                    'selection-required',
                memberships,
                failure:
                    null,
            });
        });

        it('does not perform Membership discovery when authentication truth is not eligible', async () => {
            let discoveryCalls =
                0;

            const authentication =
                createAuthenticationHarness({
                    status:
                        'anonymous',
                    failure:
                        null,
                });

            const runtime =
                createMembershipContextRuntime(
                    createOperations({
                        async discover() {
                            discoveryCalls +=
                                1;

                            return discoverySuccess;
                        },
                    }),
                    authentication.runtime,
                );

            const state =
                await runtime.bootstrap();

            expect(
                discoveryCalls,
            ).toBe(0);

            expect(state).toEqual({
                status:
                    'unresolved',
            });
        });

        it('propagates BrowserSession expiry discovered through Membership discovery back to authentication truth', async () => {
            const authentication =
                createAuthenticationHarness({
                    status:
                        'membership-context-required',
                    failure:
                        membershipRequiredFailure,
                });

            const runtime =
                createMembershipContextRuntime(
                    createOperations({
                        async discover() {
                            return sessionRequiredFailure;
                        },
                    }),
                    authentication.runtime,
                );

            const state =
                await runtime.bootstrap();

            expect(state).toEqual({
                status:
                    'unresolved',
            });

            expect(
                authentication
                    .observedFailures,
            ).toEqual([
                sessionRequiredFailure,
            ]);

            expect(
                authentication.getState()
                    .status,
            ).toBe(
                'anonymous',
            );
        });

        it('fails closed when successful Membership discovery omits its required payload', async () => {
            const authentication =
                createAuthenticationHarness({
                    status:
                        'membership-context-required',
                    failure:
                        membershipRequiredFailure,
                });

            const runtime =
                createMembershipContextRuntime(
                    createOperations({
                        async discover() {
                            return {
                                ok: true,
                                status: 200,
                                data:
                                    undefined,
                            };
                        },
                    }),
                    authentication.runtime,
                );

            const state =
                await runtime.bootstrap();

            expect(state).toEqual({
                status:
                    'unavailable',
                context:
                    null,
                failure: {
                    ok: false,
                    kind:
                        'protocol',
                    status: 200,
                    message:
                        'EduCore API returned an unexpected error response.',
                },
            });
        });

        it('fails closed to unavailable when Membership discovery cannot resolve safely', async () => {
            const authentication =
                createAuthenticationHarness({
                    status:
                        'membership-context-required',
                    failure:
                        membershipRequiredFailure,
                });

            const runtime =
                createMembershipContextRuntime(
                    createOperations({
                        async discover() {
                            return networkFailure;
                        },
                    }),
                    authentication.runtime,
                );

            const state =
                await runtime.bootstrap();

            expect(state).toEqual({
                status:
                    'unavailable',
                context:
                    null,
                failure:
                    networkFailure,
            });
        });

        it('ignores a late cancelled discovery when a newer discovery already resolved', async () => {
            let discoveryCalls =
                0;

            const authentication =
                createAuthenticationHarness({
                    status:
                        'membership-context-required',
                    failure:
                        membershipRequiredFailure,
                });

            const operations =
                createOperations({
                    discover(
                        options,
                    ) {
                        discoveryCalls +=
                            1;

                        if (
                            discoveryCalls
                                === 1
                        ) {
                            return new Promise(
                                (
                                    resolve,
                                ) => {
                                    options
                                        ?.signal
                                        ?.addEventListener(
                                            'abort',
                                            () => {
                                                setTimeout(
                                                    () => {
                                                        resolve(
                                                            abortedFailure,
                                                        );
                                                    },
                                                    10,
                                                );
                                            },
                                            {
                                                once:
                                                    true,
                                            },
                                        );
                                },
                            );
                        }

                        return Promise.resolve(
                            discoverySuccess,
                        );
                    },
                });

            const runtime =
                createMembershipContextRuntime(
                    operations,
                    authentication.runtime,
                );

            const controller =
                new AbortController();

            const first =
                runtime.bootstrap({
                    signal:
                        controller.signal,
                });

            controller.abort();

            const second =
                await runtime.bootstrap();

            expect(second).toEqual({
                status:
                    'selection-required',
                memberships,
                failure:
                    null,
            });

            await first;

            expect(
                runtime.getState(),
            ).toEqual(
                second,
            );

            expect(
                discoveryCalls,
            ).toBe(2);
        });

        it('commits a Membership switch only after canonical authentication confirms the target context', async () => {
            let switchTarget:
                string | null = null;

            const authentication =
                createAuthenticationHarness(
                    authenticatedA,
                    async (
                        options,
                    ) => {
                        expect(
                            options?.membershipId,
                        ).toBe(
                            membershipBId,
                        );

                        return authenticatedB;
                    },
                );

            const runtime =
                createMembershipContextRuntime(
                    createOperations({
                        async switchMembership(
                            membershipId,
                        ) {
                            switchTarget =
                                membershipId;

                            return switchSuccess;
                        },
                    }),
                    authentication.runtime,
                );

            await runtime.bootstrap();

            const state =
                await runtime.switchMembership(
                    membershipBId,
                );

            expect(
                switchTarget,
            ).toBe(
                membershipBId,
            );

            expect(
                authentication
                    .bootstrapMembershipIds,
            ).toEqual([
                membershipBId,
            ]);

            expect(state).toEqual({
                status:
                    'ready',
                memberships,
                context: {
                    membership:
                        authenticatedB
                            .identity
                            .membership,
                    tenant:
                        authenticatedB
                            .identity
                            .tenant,
                },
                failure:
                    null,
            });
        });

        it('keeps the previous canonical context when Browser Membership credential preparation fails', async () => {
            const authentication =
                createAuthenticationHarness(
                    authenticatedA,
                );

            const runtime =
                createMembershipContextRuntime(
                    createOperations({
                        async switchMembership() {
                            return switchDeniedFailure;
                        },
                    }),
                    authentication.runtime,
                );

            await runtime.bootstrap();

            const state =
                await runtime.switchMembership(
                    membershipBId,
                );

            expect(state).toEqual({
                status:
                    'ready',
                memberships,
                context: {
                    membership:
                        authenticatedA
                            .identity
                            .membership,
                    tenant:
                        authenticatedA
                            .identity
                            .tenant,
                },
                failure:
                    switchDeniedFailure,
            });

            expect(
                authentication
                    .bootstrapMembershipIds,
            ).toEqual([]);
        });

        it('resets Membership truth when BrowserSession expires during switch preparation', async () => {
            const authentication =
                createAuthenticationHarness(
                    authenticatedA,
                );

            const runtime =
                createMembershipContextRuntime(
                    createOperations({
                        async switchMembership() {
                            return sessionRequiredFailure;
                        },
                    }),
                    authentication.runtime,
                );

            await runtime.bootstrap();

            const state =
                await runtime.switchMembership(
                    membershipBId,
                );

            expect(state).toEqual({
                status:
                    'unresolved',
            });

            expect(
                authentication
                    .observedFailures,
            ).toEqual([
                sessionRequiredFailure,
            ]);

            expect(
                authentication.getState()
                    .status,
            ).toBe(
                'anonymous',
            );
        });

        it('resets Membership truth when target canonical authentication cannot be confirmed', async () => {
            const authentication =
                createAuthenticationHarness(
                    authenticatedA,
                    async () => ({
                        status:
                            'membership-context-required',
                        failure:
                            membershipRequiredFailure,
                    }),
                );

            const runtime =
                createMembershipContextRuntime(
                    createOperations(),
                    authentication.runtime,
                );

            await runtime.bootstrap();

            const state =
                await runtime.switchMembership(
                    membershipBId,
                );

            expect(state).toEqual({
                status:
                    'unresolved',
            });

            expect(
                authentication.getState()
                    .status,
            ).toBe(
                'membership-context-required',
            );
        });

        it('restores the previous stable Membership context when switch preparation is cancelled', async () => {
            const authentication =
                createAuthenticationHarness(
                    authenticatedA,
                );

            const runtime =
                createMembershipContextRuntime(
                    createOperations({
                        async switchMembership() {
                            return abortedFailure;
                        },
                    }),
                    authentication.runtime,
                );

            await runtime.bootstrap();

            const before =
                runtime.getState();

            const after =
                await runtime.switchMembership(
                    membershipBId,
                );

            expect(after).toBe(
                before,
            );

            expect(
                authentication
                    .bootstrapMembershipIds,
            ).toEqual([]);
        });
    },
);
