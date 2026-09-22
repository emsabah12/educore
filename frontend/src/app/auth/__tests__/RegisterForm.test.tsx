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
    RegisterForm,
} from '@/app/auth/RegisterForm';

function fillValidForm(): void {
    fireEvent.change(
        screen.getByLabelText(
            'Nama sekolah/institusi',
        ),
        {
            target: {
                value:
                    'SMA Negeri Uji Coba',
            },
        },
    );

    fireEvent.change(
        screen.getByLabelText(
            'Subdomain',
        ),
        {
            target: {
                value:
                    'sma-uji-coba',
            },
        },
    );

    fireEvent.change(
        screen.getByLabelText(
            'Nama Anda',
        ),
        {
            target: {
                value:
                    'Kepala Sekolah Baru',
            },
        },
    );

    fireEvent.change(
        screen.getByLabelText(
            'Email Anda',
        ),
        {
            target: {
                value:
                    'admin-baru@example.com',
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
    'RegisterForm',
    () => {
        it('renders all five registration fields with appropriate autocomplete semantics', () => {
            render(
                <RegisterForm
                    onValidatedSubmit={
                        vi.fn()
                    }
                />,
            );

            expect(
                screen.getByLabelText(
                    'Nama sekolah/institusi',
                ),
            ).toHaveAttribute(
                'autocomplete',
                'organization',
            );

            expect(
                screen.getByLabelText(
                    'Subdomain',
                ),
            ).toHaveAttribute(
                'type',
                'text',
            );

            expect(
                screen.getByLabelText(
                    'Nama Anda',
                ),
            ).toHaveAttribute(
                'autocomplete',
                'name',
            );

            expect(
                screen.getByLabelText(
                    'Email Anda',
                ),
            ).toHaveAttribute(
                'type',
                'email',
            );

            const password =
                screen.getByLabelText(
                    'Password',
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
                'new-password',
            );
        });

        it('shows all locally detectable validation errors without dispatching registration intent', async () => {
            const onValidatedSubmit =
                vi.fn();

            render(
                <RegisterForm
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
                            'Daftar',
                    },
                ),
            );

            expect(
                await screen.findByText(
                    'Nama sekolah/institusi wajib diisi, minimal 3 karakter.',
                ),
            ).toBeInTheDocument();

            expect(
                screen.getByText(
                    'Subdomain wajib diisi.',
                ),
            ).toBeInTheDocument();

            expect(
                screen.getByText(
                    'Nama Anda wajib diisi, minimal 3 karakter.',
                ),
            ).toBeInTheDocument();

            expect(
                screen.getByText(
                    'Email wajib diisi.',
                ),
            ).toBeInTheDocument();

            expect(
                screen.getByText(
                    'Password wajib diisi, minimal 8 karakter.',
                ),
            ).toBeInTheDocument();

            expect(
                onValidatedSubmit,
            ).not.toHaveBeenCalled();
        });

        it('clears subdomain error when subdomain is edited again', async () => {
            render(
                <RegisterForm
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
                            'Daftar',
                    },
                ),
            );

            expect(
                await screen.findByText(
                    'Subdomain wajib diisi.',
                ),
            ).toBeInTheDocument();

            fireEvent.change(
                screen.getByLabelText(
                    'Subdomain',
                ),
                {
                    target: {
                        value:
                            'sekolah-baru',
                    },
                },
            );

            expect(
                screen.queryByText(
                    'Subdomain wajib diisi.',
                ),
            ).not.toBeInTheDocument();
        });

        it('submits the canonical Tenant self-registration request', async () => {
            const onValidatedSubmit =
                vi.fn();

            render(
                <RegisterForm
                    onValidatedSubmit={
                        onValidatedSubmit
                    }
                />,
            );

            fillValidForm();

            fireEvent.click(
                screen.getByRole(
                    'button',
                    {
                        name:
                            'Daftar',
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
                name:
                    'SMA Negeri Uji Coba',

                subdomain:
                    'sma-uji-coba',

                admin_name:
                    'Kepala Sekolah Baru',

                admin_email:
                    'admin-baru@example.com',

                admin_password:
                    'secret-value',
            });
        });

        it('prevents submission while disabled', () => {
            const onValidatedSubmit =
                vi.fn();

            render(
                <RegisterForm
                    disabled
                    onValidatedSubmit={
                        onValidatedSubmit
                    }
                />,
            );

            expect(
                screen.getByLabelText(
                    'Nama sekolah/institusi',
                ),
            ).toBeDisabled();

            expect(
                screen.getByLabelText(
                    'Subdomain',
                ),
            ).toBeDisabled();

            expect(
                screen.getByLabelText(
                    'Nama Anda',
                ),
            ).toBeDisabled();

            expect(
                screen.getByLabelText(
                    'Email Anda',
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
                            'Daftar',
                    },
                ),
            ).toBeDisabled();

            expect(
                onValidatedSubmit,
            ).not.toHaveBeenCalled();
        });

        it('renders controlled subdomain server errors without exposing transport details', () => {
            render(
                <RegisterForm
                    externalErrors={{
                        subdomain:
                            'Subdomain tidak dapat diterima. Kemungkinan sudah dipakai institusi lain.',
                    }}
                    formError="Periksa kembali data yang ditandai."
                    onValidatedSubmit={
                        vi.fn()
                    }
                />,
            );

            expect(
                screen.getByText(
                    'Periksa kembali data yang ditandai.',
                ),
            ).toHaveAttribute(
                'role',
                'alert',
            );

            expect(
                screen.getByText(
                    'Subdomain tidak dapat diterima. Kemungkinan sudah dipakai institusi lain.',
                ),
            ).toHaveAttribute(
                'role',
                'alert',
            );

            expect(
                screen.getByLabelText(
                    'Subdomain',
                ),
            ).toHaveAttribute(
                'aria-invalid',
                'true',
            );
        });

        it('notifies application boundary for all five inputs', () => {
            const onInputChange =
                vi.fn();

            render(
                <RegisterForm
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
                5,
            );
        });
    },
);
