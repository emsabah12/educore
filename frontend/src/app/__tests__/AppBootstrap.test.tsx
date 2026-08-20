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

import { AppBootstrap } from '@/app/AppBootstrap';
import { createApplicationRuntime } from '@/app/runtime';
import { apiMockServer } from '@/test/server';

afterEach(() => {
    window.history.replaceState(
        null,
        '',
        '/',
    );
});

describe('AppBootstrap', () => {
    it('mounts the application through the composed providers and resolves initial authentication truth', async () => {
        window.history.replaceState(
            null,
            '',
            '/',
        );

        apiMockServer.use(
            http.get(
                `${window.location.origin}/api/v1/auth/me`,
                () => HttpResponse.json(
                    {
                        status:
                            'error',
                        code:
                            'BROWSER_SESSION_AUTHENTICATION_REQUIRED',
                        message:
                            'Authenticated browser session is required.',
                    },
                    {
                        status: 401,
                    },
                ),
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
                'anonymous',
            );
        });
    });
});
