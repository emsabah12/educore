import {
    describe,
    expect,
    it,
} from 'vitest';

import {
    browserAuthReducer,
    createInitialBrowserAuthState,
    type AuthenticatedBootstrapData,
    type BrowserAuthState,
    type BrowserLoginData,
} from '@/platform/auth';

import type {
    BrowserApiResponseFailure,
} from '@/platform/api';

const globalIdentity:
    BrowserLoginData = {
        context_type:
            'identity',

        user: {
            id:
                '018f3b6a-7c20-7abc-8def-1234567890ab',

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

const membershipIdentity:
    AuthenticatedBootstrapData = {
        user: {
            id:
                '018f3b6a-7c20-7abc-8def-1234567890ab',

            email:
                'member@example.com',
        },

        person: {
            id:
                '018f3b6a-7c20-7bcd-8def-1234567890ab',

            name:
                'EduCore Member',
        },

        membership: {
            id:
                '018f3b6a-7c20-7cde-8def-1234567890ab',

            status:
                'ACTIVE',
        },

        tenant: {
            id:
                '018f3b6a-7c20-7def-8abc-1234567890ab',

            name:
                'EduCore School',

            subdomain:
                'school',
        },
    };

const authenticationRequiredFailure:
    BrowserApiResponseFailure = {
        ok:
            false,

        kind:
            'response',

        status:
            401,

        error: {
            status:
                'error',

            code:
                'BROWSER_SESSION_AUTHENTICATION_REQUIRED',

            message:
                'Browser session authentication is required.',
        },
    };

const contextRequiredFailure:
    BrowserApiResponseFailure = {
        ok:
            false,

        kind:
            'response',

        status:
            403,

        error: {
            status:
                'error',

            code:
                'BROWSER_MEMBERSHIP_CONTEXT_REQUIRED',

            message:
                'Browser Membership context is required.',
        },
    };

describe(
    'browserAuthReducer',
    () => {
        it('starts with unknown authentication truth', () => {
            expect(
                createInitialBrowserAuthState(),
            ).toEqual({
                status:
                    'unknown',
            });
        });

        it('models fresh global login as identity-authenticated without Membership resolution', () => {
            let state:
                BrowserAuthState = {
                status:
                    'anonymous',

                failure:
                    null,
            };

            state =
                browserAuthReducer(
                    state,
                    {
                        type:
                            'LOGIN_STARTED',
                    },
                );

            expect(
                state,
            ).toEqual({
                status:
                    'authenticating',
            });

            state =
                browserAuthReducer(
                    state,
                    {
                        type:
                            'LOGIN_ACCEPTED',

                        identity:
                            globalIdentity,
                    },
                );

            expect(
                state,
            ).toEqual({
                status:
                    'identity-authenticated',

                identity:
                    globalIdentity,
            });
        });

        it('can promote identity-authenticated state only after canonical Membership context is verified', () => {
            const state =
                browserAuthReducer(
                    {
                        status:
                            'identity-authenticated',

                        identity:
                            globalIdentity,
                    },
                    {
                        type:
                            'BECAME_AUTHENTICATED',

                        identity:
                            membershipIdentity,
                    },
                );

            expect(
                state,
            ).toEqual({
                status:
                    'authenticated',

                identity:
                    membershipIdentity,
            });
        });

        it('keeps membership-context-required distinct from anonymous', () => {
            const state =
                browserAuthReducer(
                    createInitialBrowserAuthState(),
                    {
                        type:
                            'CONTEXT_REQUIRED',

                        failure:
                            contextRequiredFailure,
                    },
                );

            expect(
                state.status,
            ).toBe(
                'membership-context-required',
            );
        });

        it('allows identity-only authenticated user to begin logout', () => {
            const state =
                browserAuthReducer(
                    {
                        status:
                            'identity-authenticated',

                        identity:
                            globalIdentity,
                    },
                    {
                        type:
                            'LOGOUT_STARTED',
                    },
                );

            expect(
                state,
            ).toEqual({
                status:
                    'logging-out',

                identity:
                    globalIdentity,
            });
        });

        it('models Membership-authenticated logout without losing identity before completion', () => {
            let state:
                BrowserAuthState = {
                status:
                    'authenticated',

                identity:
                    membershipIdentity,
            };

            state =
                browserAuthReducer(
                    state,
                    {
                        type:
                            'LOGOUT_STARTED',
                    },
                );

            expect(
                state,
            ).toEqual({
                status:
                    'logging-out',

                identity:
                    membershipIdentity,
            });

            state =
                browserAuthReducer(
                    state,
                    {
                        type:
                            'LOGOUT_COMPLETED',
                    },
                );

            expect(
                state,
            ).toEqual({
                status:
                    'anonymous',

                failure:
                    null,
            });
        });

        it('models identity session expiry as anonymous instead of unavailable', () => {
            const state =
                browserAuthReducer(
                    {
                        status:
                            'identity-authenticated',

                        identity:
                            globalIdentity,
                    },
                    {
                        type:
                            'SESSION_EXPIRED',

                        failure:
                            authenticationRequiredFailure,
                    },
                );

            expect(
                state,
            ).toEqual({
                status:
                    'anonymous',

                failure:
                    authenticationRequiredFailure,
            });
        });

        it('fails closed on invalid authentication transitions', () => {
            expect(() => {
                browserAuthReducer(
                    {
                        status:
                            'authenticated',

                        identity:
                            membershipIdentity,
                    },
                    {
                        type:
                            'LOGIN_STARTED',
                    },
                );
            }).toThrow(
                'Invalid EduCore BrowserAuth transition: authenticated -> LOGIN_STARTED',
            );
        });
    },
);
