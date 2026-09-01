import {
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import {
    describe,
    expect,
    it,
    vi,
} from 'vitest';

import {
    LoginForm,
} from '@/app/auth/LoginForm';

function fillValidForm(): void {
    fireEvent.change(
        screen.getByLabelText(
            'Email atau username',
        ),
        {
            target: {
                value:
                    'member@example.com',
            },
        },
    );

    fireEvent.change(
        screen.getByLabelText(
            'Password',
        ),
        {
            target: {
                value:
                    'secret-value',
            },
        },
    );
}

describe(
    'LoginForm',
    () => {
        it('renders global identifier and password fields with browser credential autocomplete semantics', () => {
            render(
                <LoginForm
                    onValidatedSubmit={
                        vi.fn()
                    }
                />,
            );

            const identifier =
                screen.getByLabelText(
                    'Email atau username',
                );

            const password =
                screen.getByLabelText(
                    'Password',
                );

            expect(
                identifier,
            ).toHaveAttribute(
                'type',
                'text',
            );

            expect(
                identifier,
            ).toHaveAttribute(
                'autocomplete',
                'username',
            );

            expect(
                password,
            ).toHaveAttribute(
                'type',
                'password',
            );

            expect(
                password,
            ).toHaveAttribute(
                'autocomplete',
                'current-password',
            );

            expect(
                screen.queryByLabelText(
                    'Tenant UUID',
                ),
            ).not.toBeInTheDocument();
        });

        it('shows all locally detectable validation errors without dispatching authentication intent', async () => {
            const onValidatedSubmit =
                vi.fn();

            render(
                <LoginForm
                    onValidatedSubmit={
                        onValidatedSubmit
                    }
                />,
            );

            fireEvent.click(
                screen.getByRole(
                    'button',
                    {
                        name:
                            'Masuk',
                    },
                ),
            );

            expect(
                await screen.findByText(
                    'Identifier wajib diisi.',
                ),
            ).toBeInTheDocument();

            expect(
                screen.getByText(
                    'Password wajib diisi.',
                ),
            ).toBeInTheDocument();

            expect(
                onValidatedSubmit,
            ).not.toHaveBeenCalled();
        });

        it('marks invalid controls for assistive technology', async () => {
            render(
                <LoginForm
                    onValidatedSubmit={
                        vi.fn()
                    }
                />,
            );

            fireEvent.click(
                screen.getByRole(
                    'button',
                    {
                        name:
                            'Masuk',
                    },
                ),
            );

            expect(
                await screen.findByLabelText(
                    'Email atau username',
                ),
            ).toHaveAttribute(
                'aria-invalid',
                'true',
            );

            expect(
                screen.getByLabelText(
                    'Password',
                ),
            ).toHaveAttribute(
                'aria-invalid',
                'true',
            );
        });

        it('clears identifier error when identifier is edited again', async () => {
            render(
                <LoginForm
                    onValidatedSubmit={
                        vi.fn()
                    }
                />,
            );

            fireEvent.click(
                screen.getByRole(
                    'button',
                    {
                        name:
                            'Masuk',
                    },
                ),
            );

            expect(
                await screen.findByText(
                    'Identifier wajib diisi.',
                ),
            ).toBeInTheDocument();

            fireEvent.change(
                screen.getByLabelText(
                    'Email atau username',
                ),
                {
                    target: {
                        value:
                            'school.admin',
                    },
                },
            );

            expect(
                screen.queryByText(
                    'Identifier wajib diisi.',
                ),
            ).not.toBeInTheDocument();
        });

        it('submits only identifier and password', async () => {
            const onValidatedSubmit =
                vi.fn();

            render(
                <LoginForm
                    onValidatedSubmit={
                        onValidatedSubmit
                    }
                />,
            );

            fireEvent.change(
                screen.getByLabelText(
                    'Email atau username',
                ),
                {
                    target: {
                        value:
                            '  school.admin  ',
                    },
                },
            );

            fireEvent.change(
                screen.getByLabelText(
                    'Password',
                ),
                {
                    target: {
                        value:
                            '  secret value  ',
                    },
                },
            );

            fireEvent.click(
                screen.getByRole(
                    'button',
                    {
                        name:
                            'Masuk',
                    },
                ),
            );

            await waitFor(() => {
                expect(
                    onValidatedSubmit,
                ).toHaveBeenCalledTimes(
                    1,
                );
            });

            expect(
                onValidatedSubmit,
            ).toHaveBeenCalledWith({
                identifier:
                    'school.admin',

                password:
                    '  secret value  ',
            });
        });

        it('prevents submission while disabled', () => {
            const onValidatedSubmit =
                vi.fn();

            render(
                <LoginForm
                    disabled
                    onValidatedSubmit={
                        onValidatedSubmit
                    }
                />,
            );

            expect(
                screen.getByLabelText(
                    'Email atau username',
                ),
            ).toBeDisabled();

            expect(
                screen.getByLabelText(
                    'Password',
                ),
            ).toBeDisabled();

            expect(
                screen.getByRole(
                    'button',
                    {
                        name:
                            'Masuk',
                    },
                ),
            ).toBeDisabled();

            expect(
                onValidatedSubmit,
            ).not.toHaveBeenCalled();
        });

        it('does not clear password after successful validated handoff', async () => {
            render(
                <LoginForm
                    onValidatedSubmit={
                        vi.fn()
                    }
                />,
            );

            fillValidForm();

            fireEvent.click(
                screen.getByRole(
                    'button',
                    {
                        name:
                            'Masuk',
                    },
                ),
            );

            await waitFor(() => {
                expect(
                    screen.getByLabelText(
                        'Password',
                    ),
                ).toHaveValue(
                    'secret-value',
                );
            });
        });

        it('renders controlled identifier server errors without exposing transport details', () => {
            render(
                <LoginForm
                    externalErrors={{
                        identifier:
                            'Identifier tidak dapat diterima. Periksa kembali email atau username Anda.',
                    }}
                    formError="Periksa kembali data login yang ditandai."
                    onValidatedSubmit={
                        vi.fn()
                    }
                />,
            );

            expect(
                screen.getByText(
                    'Periksa kembali data login yang ditandai.',
                ),
            ).toHaveAttribute(
                'role',
                'alert',
            );

            expect(
                screen.getByText(
                    'Identifier tidak dapat diterima. Periksa kembali email atau username Anda.',
                ),
            ).toHaveAttribute(
                'role',
                'alert',
            );

            expect(
                screen.getByLabelText(
                    'Email atau username',
                ),
            ).toHaveAttribute(
                'aria-invalid',
                'true',
            );
        });

        it('notifies application boundary for both credential inputs', () => {
            const onInputChange =
                vi.fn();

            render(
                <LoginForm
                    onInputChange={
                        onInputChange
                    }
                    onValidatedSubmit={
                        vi.fn()
                    }
                />,
            );

            fillValidForm();

            expect(
                onInputChange,
            ).toHaveBeenCalledTimes(
                2,
            );
        });
    },
);
