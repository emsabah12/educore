import {
    render,
    screen,
} from '@testing-library/react';
import {
    describe,
    expect,
    it,
    vi,
} from 'vitest';

import { ApplicationErrorBoundary } from '@/app/ApplicationErrorBoundary';

function BrokenComponent(): never {
    throw new Error('Synthetic render failure');
}

describe('ApplicationErrorBoundary', () => {
    it('fails closed to controlled recovery UI', () => {
        const consoleError = vi
            .spyOn(console, 'error')
            .mockImplementation(() => undefined);

        render(
            <ApplicationErrorBoundary>
                <BrokenComponent />
            </ApplicationErrorBoundary>,
        );

        expect(
            screen.getByRole(
                'heading',
                {
                    name: 'Aplikasi tidak dapat dimuat',
                },
            ),
        ).toBeInTheDocument();

        expect(consoleError).toHaveBeenCalled();

        consoleError.mockRestore();
    });
});
