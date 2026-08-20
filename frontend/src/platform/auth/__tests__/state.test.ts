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
} from '@/platform/auth';
import type {
    BrowserApiResponseFailure,
} from '@/platform/api';

const loginContext = {
    membership_id:
        '018f3b6a-7c20-7abc-8def-1234567890ab',
    tenant_id:
        '018f3b6a-7c20-7def-9abc-1234567890ab',
};

const identity: AuthenticatedBootstrapData = {
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
        ok: false,
        kind: 'response',
        status: 401,
        error: {
            status: 'error',
            code:
                'BROWSER_SESSION_AUTHENTICATION_REQUIRED',
            message:
                'Browser session authentication is required.',
        },
    };

const contextRequiredFailure:
    BrowserApiResponseFailure = {
        ok: false,
        kind: 'response',
        status: 403,
        error: {
            status: 'error',
            code:
                'BROWSER_MEMBERSHIP_CONTEXT_REQUIRED',
            message:
                'Browser Membership context is required.',
        },
    };

describe('browserAuthReducer', () => {
    it('starts with unknown authentication truth', () => {
        expect(
            createInitialBrowserAuthState(),
        ).toEqual({
            status: 'unknown',
        });
    });

    it('models the login-to-context-resolution flow', () => {
        let state: BrowserAuthState = {
            status: 'anonymous',
            failure: null,
        };

        state = browserAuthReducer(
            state,
            {
                type: 'LOGIN_STARTED',
            },
        );

        expect(state).toEqual({
            status: 'authenticating',
        });

        state = browserAuthReducer(
            state,
            {
                type: 'LOGIN_ACCEPTED',
                login: loginContext,
            },
        );

        expect(state).toEqual({
            status: 'resolving-context',
            login: loginContext,
        });

        state = browserAuthReducer(
            state,
            {
                type:
                    'BECAME_AUTHENTICATED',
                identity,
            },
        );

        expect(state).toEqual({
            status: 'authenticated',
            identity,
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

        expect(state.status).toBe(
            'membership-context-required',
        );

        expect(state).not.toEqual({
            status: 'anonymous',
            failure:
                contextRequiredFailure,
        });
    });

    it('models authenticated logout without losing identity before completion', () => {
        let state: BrowserAuthState = {
            status: 'authenticated',
            identity,
        };

        state = browserAuthReducer(
            state,
            {
                type: 'LOGOUT_STARTED',
            },
        );

        expect(state).toEqual({
            status: 'logging-out',
            identity,
        });

        state = browserAuthReducer(
            state,
            {
                type:
                    'LOGOUT_COMPLETED',
            },
        );

        expect(state).toEqual({
            status: 'anonymous',
            failure: null,
        });
    });

    it('models session expiry as anonymous instead of unavailable', () => {
        const state =
            browserAuthReducer(
                {
                    status:
                        'authenticated',
                    identity,
                },
                {
                    type:
                        'SESSION_EXPIRED',
                    failure:
                        authenticationRequiredFailure,
                },
            );

        expect(state).toEqual({
            status: 'anonymous',
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
                    identity,
                },
                {
                    type: 'LOGIN_STARTED',
                },
            );
        }).toThrow(
            'Invalid EduCore BrowserAuth transition: authenticated -> LOGIN_STARTED',
        );
    });
});
