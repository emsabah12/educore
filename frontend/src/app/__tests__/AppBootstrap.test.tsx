import {
    render,
    screen,
} from '@testing-library/react';
import {
    afterEach,
    describe,
    expect,
    it,
} from 'vitest';

import { AppBootstrap } from '@/app/AppBootstrap';
import { createApplicationRuntime } from '@/app/runtime';

afterEach(() => {
    window.history.replaceState(
        null,
        '',
        '/',
    );
});

describe('AppBootstrap', () => {
    it('mounts the application through the composed providers', async () => {
        window.history.replaceState(
            null,
            '',
            '/',
        );

        const runtime = createApplicationRuntime();

        render(
            <AppBootstrap runtime={runtime} />,
        );

        expect(
            await screen.findByRole(
                'heading',
                {
                    name: 'Frontend Foundation',
                },
            ),
        ).toBeInTheDocument();
    });
});
