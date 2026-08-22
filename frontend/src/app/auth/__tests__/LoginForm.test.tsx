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

const tenantUuid =
    '018f3b6a-7c20-7cde-8def-1234567890ab';

function fillValidForm(): void {
    fireEvent.change(
        screen.getByLabelText(
            'Email',
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

    fireEvent.change(
        screen.getByLabelText(
            'Tenant UUID',
        ),
        {
            target: {
                value:
                    tenantUuid,
            },
        },
    );
}

describe(
    'LoginForm',
    () => {
        it('renders accessible login fields with browser credential autocomplete semantics', () => {
            render(
                <LoginForm
                    onValidatedSubmit={
                        vi.fn()
                    }
                />,
            );

            const email =
                screen.getByLabelText(
                    'Email',
                );

            const password =
                screen.getByLabelText(
                    'Password',
                );

            const tenant =
                screen.getByLabelText(
                    'Tenant UUID',
                );

            expect(
                email,
            ).toHaveAttribute(
                'type',
                'email',
            );

            expect(
                email,
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
                tenant,
            ).toHaveAttribute(
                'autocomplete',
                'off',
            );

            expect(
                screen.getByRole(
                    'button',
                    {
                        name:
                            'Masuk',
                    },
                ),
            ).toHaveAttribute(
                'type',
                'submit',
            );
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

            fireEvent.change(
                screen.getByLabelText(
                    'Tenant UUID',
                ),
                {
                    target: {
                        value:
                            'invalid',
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

            expect(
                await screen.findByText(
                    'Email wajib diisi.',
                ),
            ).toBeInTheDocument();

            expect(
                screen.getByText(
                    'Password wajib diisi.',
                ),
            ).toBeInTheDocument();

            expect(
                screen.getByText(
                    'Tenant UUID tidak valid.',
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
                    'Email',
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

            expect(
                screen.getByLabelText(
                    'Tenant UUID',
                ),
            ).toHaveAttribute(
                'aria-invalid',
                'true',
            );
        });

        it('clears a field error when that field is edited again', async () => {
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
                    'Email wajib diisi.',
                ),
            ).toBeInTheDocument();

            fireEvent.change(
                screen.getByLabelText(
                    'Email',
                ),
                {
                    target: {
                        value:
                            'member@example.com',
                    },
                },
            );

            expect(
                screen.queryByText(
                    'Email wajib diisi.',
                ),
            ).not.toBeInTheDocument();
        });

        it('submits only a validated canonical Browser login request', async () => {
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
                    'Email',
                ),
                {
                    target: {
                        value:
                            '  MEMBER@EXAMPLE.COM  ',
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

            fireEvent.change(
                screen.getByLabelText(
                    'Tenant UUID',
                ),
                {
                    target: {
                        value:
                            `  ${tenantUuid}  `,
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
                email:
                    'member@example.com',

                password:
                    '  secret value  ',

                tenant_uuid:
                    tenantUuid,
            });
        });

        it('prevents submission while the form is disabled', () => {
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
                    'Email',
                ),
            ).toBeDisabled();

            expect(
                screen.getByLabelText(
                    'Password',
                ),
            ).toBeDisabled();

            expect(
                screen.getByLabelText(
                    'Tenant UUID',
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

        it('does not clear the password after successful validated handoff', async () => {
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

                it('renders controlled form-level and server field errors without exposing transport details', () => {
            render(
                <LoginForm
                    externalErrors={{
                        email:
                            'Email tidak dapat diterima. Periksa kembali email Anda.',
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
                    'Email tidak dapat diterima. Periksa kembali email Anda.',
                ),
            ).toHaveAttribute(
                'role',
                'alert',
            );

            expect(
                screen.getByLabelText(
                    'Email',
                ),
            ).toHaveAttribute(
                'aria-invalid',
                'true',
            );
        });

                it('notifies the application boundary when any login credential input changes', () => {
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

            fireEvent.change(
                screen.getByLabelText(
                    'Email',
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

            fireEvent.change(
                screen.getByLabelText(
                    'Tenant UUID',
                ),
                {
                    target: {
                        value:
                            '018f3b6a-7c20-7cde-8def-1234567890ab',
                    },
                },
            );

            expect(
                onInputChange,
            ).toHaveBeenCalledTimes(
                3,
            );
        });
    },
);
