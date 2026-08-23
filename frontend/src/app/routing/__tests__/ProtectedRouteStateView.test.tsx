import {
    fireEvent,
    render,
    screen,
} from '@testing-library/react';
import {
    describe,
    expect,
    it,
    vi,
} from 'vitest';

import {
    ProtectedRouteStateView,
} from '@/app/routing/ProtectedRouteStateView';
import type {
    BrowserApiFailure,
} from '@/platform/api';

const networkFailure:
    BrowserApiFailure = {
        ok:
            false,

        kind:
            'network',

        cause:
            new Error(
                'sensitive transport detail',
            ),
    };

describe(
    'ProtectedRouteStateView',
    () => {
        it('renders authentication bootstrap as pending instead of access denied', () => {
            render(
                <ProtectedRouteStateView
                    decision={{
                        status:
                            'pending',

                        source:
                            'authentication',
                    }}
                />,
            );

            expect(
                screen.getByRole(
                    'heading',
                    {
                        name:
                            'Menyiapkan halaman',
                    },
                ),
            ).toBeInTheDocument();

            expect(
                screen.getByText(
                    'Sedang memeriksa sesi Anda.',
                ),
            ).toBeInTheDocument();

            expect(
                screen.queryByText(
                    'Akses ditolak',
                ),
            ).not.toBeInTheDocument();
        });

        it('renders Membership selection as its own controlled state', () => {
            render(
                <ProtectedRouteStateView
                    decision={{
                        status:
                            'membership-required',
                    }}
                />,
            );

            expect(
                screen.getByRole(
                    'heading',
                    {
                        name:
                            'Pilih Membership',
                    },
                ),
            ).toBeInTheDocument();
        });

        it('keeps an empty Membership catalog distinct from access denial', () => {
            render(
                <ProtectedRouteStateView
                    decision={{
                        status:
                            'membership-empty',
                    }}
                />,
            );

            expect(
                screen.getByRole(
                    'heading',
                    {
                        name:
                            'Membership belum tersedia',
                    },
                ),
            ).toBeInTheDocument();

            expect(
                screen.queryByText(
                    'Akses ditolak',
                ),
            ).not.toBeInTheDocument();
        });

        it('renders unavailable authorization authority without exposing raw failure details', () => {
            render(
                <ProtectedRouteStateView
                    decision={{
                        status:
                            'unavailable',

                        source:
                            'authorization',

                        failure:
                            networkFailure,
                    }}
                />,
            );

            expect(
                screen.getByRole(
                    'heading',
                    {
                        name:
                            'Konteks belum tersedia',
                    },
                ),
            ).toBeInTheDocument();

            expect(
                screen.getByText(
                    'Informasi otorisasi belum dapat dimuat.',
                ),
            ).toBeInTheDocument();

            expect(
                screen.queryByText(
                    'sensitive transport detail',
                ),
            ).not.toBeInTheDocument();
        });

        it('offers controlled retry for unavailable route authority without exposing failure internals', () => {
            const retryUnavailable =
                vi.fn();

            render(
                <ProtectedRouteStateView
                    decision={{
                        status:
                            'unavailable',

                        source:
                            'authorization',

                        failure:
                            networkFailure,
                    }}
                    onRetryUnavailable={
                        retryUnavailable
                    }
                />,
            );

            const retryButton =
                screen.getByRole(
                    'button',
                    {
                        name:
                            'Coba lagi',
                    },
                );

            expect(
                screen.queryByText(
                    'sensitive transport detail',
                ),
            ).not.toBeInTheDocument();

            fireEvent.click(
                retryButton,
            );

            expect(
                retryUnavailable,
            ).toHaveBeenCalledTimes(1);
        });

        it('renders organizational context requirement distinctly from permission denial', () => {
            render(
                <ProtectedRouteStateView
                    decision={{
                        status:
                            'context-required',

                        requiredContext:
                            'organizational',

                        currentWorkspace:
                            'TENANT',
                    }}
                />,
            );

            expect(
                screen.getByRole(
                    'heading',
                    {
                        name:
                            'Pilih Workspace organisasi',
                    },
                ),
            ).toBeInTheDocument();

            expect(
                screen.queryByText(
                    'Akses ditolak',
                ),
            ).not.toBeInTheDocument();
        });

        it('renders Tenant context requirement distinctly from organizational selection', () => {
            render(
                <ProtectedRouteStateView
                    decision={{
                        status:
                            'context-required',

                        requiredContext:
                            'tenant',

                        currentWorkspace:
                            'ORGANIZATION',
                    }}
                />,
            );

            expect(
                screen.getByRole(
                    'heading',
                    {
                        name:
                            'Gunakan Workspace Tenant',
                    },
                ),
            ).toBeInTheDocument();
        });

        it('renders canonical permission denial as controlled Access Denied UX', () => {
            render(
                <ProtectedRouteStateView
                    decision={{
                        status:
                            'denied',
                    }}
                />,
            );

            expect(
                screen.getByRole(
                    'heading',
                    {
                        name:
                            'Akses ditolak',
                    },
                ),
            ).toBeInTheDocument();

            expect(
                screen.getByText(
                    /permission yang diperlukan/,
                ),
            ).toBeInTheDocument();
        });

        it('renders Membership switching as an application-level institution transition', () => {
            render(
                <ProtectedRouteStateView
                    decision={{
                        status:
                            'pending',

                        source:
                            'membership',

                        phase:
                            'membership-switch',
                    }}
                />,
            );

            expect(
                screen.getByRole(
                    'heading',
                    {
                        name:
                            'Mengganti institusi',
                    },
                ),
            ).toBeInTheDocument();

            expect(
                screen.getByText(
                    'Sedang mengganti institusi aktif.',
                ),
            ).toBeInTheDocument();

            expect(
                screen.queryByText(
                    'Sedang memuat konteks Membership Anda.',
                ),
            ).not.toBeInTheDocument();
        });

        it('renders Workspace switching as its own context transition', () => {
            render(
                <ProtectedRouteStateView
                    decision={{
                        status:
                            'pending',

                        source:
                            'workspace',

                        phase:
                            'workspace-switch',
                    }}
                />,
            );

            expect(
                screen.getByRole(
                    'heading',
                    {
                        name:
                            'Mengganti Workspace',
                    },
                ),
            ).toBeInTheDocument();

            expect(
                screen.getByText(
                    'Sedang mengganti Workspace aktif.',
                ),
            ).toBeInTheDocument();

            expect(
                screen.queryByText(
                    'Sedang menyiapkan Workspace aktif.',
                ),
            ).not.toBeInTheDocument();
        });

        it('renders stale Workspace recovery distinctly from a normal Workspace switch', () => {
            render(
                <ProtectedRouteStateView
                    decision={{
                        status:
                            'pending',

                        source:
                            'workspace',

                        phase:
                            'workspace-recovery',
                    }}
                />,
            );

            expect(
                screen.getByRole(
                    'heading',
                    {
                        name:
                            'Memulihkan Workspace',
                    },
                ),
            ).toBeInTheDocument();

            expect(
                screen.getByText(
                    'Sedang memulihkan Workspace ke konteks yang aman.',
                ),
            ).toBeInTheDocument();

            expect(
                screen.queryByText(
                    'Sedang mengganti Workspace aktif.',
                ),
            ).not.toBeInTheDocument();
        });

        it('renders Capability loading as an unresolved authorization state', () => {
            render(
                <ProtectedRouteStateView
                    decision={{
                        status:
                            'pending',

                        source:
                            'authorization',

                        phase:
                            'capability-load',
                    }}
                />,
            );

            expect(
                screen.getByRole(
                    'heading',
                    {
                        name:
                            'Memeriksa akses',
                    },
                ),
            ).toBeInTheDocument();

            expect(
                screen.getByText(
                    'Sedang memuat informasi akses halaman.',
                ),
            ).toBeInTheDocument();

            expect(
                screen.queryByText(
                    'Akses ditolak',
                ),
            ).not.toBeInTheDocument();
        });

        it('uses source-specific pending copy without collapsing route lifecycle states', () => {
            const {
                rerender,
            } =
                render(
                    <ProtectedRouteStateView
                        decision={{
                            status:
                                'pending',

                            source:
                                'membership',
                        }}
                    />,
                );

            expect(
                screen.getByText(
                    'Sedang memuat konteks Membership Anda.',
                ),
            ).toBeInTheDocument();

            rerender(
                <ProtectedRouteStateView
                    decision={{
                        status:
                            'pending',

                        source:
                            'workspace',
                    }}
                />,
            );

            expect(
                screen.getByText(
                    'Sedang menyiapkan Workspace aktif.',
                ),
            ).toBeInTheDocument();

            rerender(
                <ProtectedRouteStateView
                    decision={{
                        status:
                            'pending',

                        source:
                            'authorization',
                    }}
                />,
            );

            expect(
                screen.getByText(
                    'Sedang memeriksa akses halaman.',
                ),
            ).toBeInTheDocument();
        });
    },
);
