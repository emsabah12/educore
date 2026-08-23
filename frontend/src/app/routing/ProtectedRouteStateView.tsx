import type {
    ProtectedRouteAccessDecision,
    ProtectedRoutePendingPhase,
    ProtectedRoutePendingSource,
    ProtectedRouteUnavailableSource,
} from '@/platform/routing';

export type ControlledProtectedRouteStateDecision =
    Exclude<
        ProtectedRouteAccessDecision,
        | {
            readonly status:
                'allowed';
        }
        | {
            readonly status:
                'unauthenticated';
        }
    >;

export interface ProtectedRouteStateViewProps {
    readonly decision:
        ControlledProtectedRouteStateDecision;

    readonly onRetryUnavailable?:
        () => void;
}

function pendingMessage(
    source:
        ProtectedRoutePendingSource,
): string {
    switch (
        source
    ) {
        case 'authentication':
            return 'Sedang memeriksa sesi Anda.';

        case 'membership':
            return 'Sedang memuat konteks Membership Anda.';

        case 'workspace':
            return 'Sedang menyiapkan Workspace aktif.';

        case 'authorization':
            return 'Sedang memeriksa akses halaman.';
    }
}

interface PendingPresentation {
    readonly title:
        string;

    readonly description:
        string;
}

function pendingPresentation(
    source:
        ProtectedRoutePendingSource,
    phase:
        ProtectedRoutePendingPhase | undefined,
): PendingPresentation {
    switch (
        phase
    ) {
        case 'membership-switch':
            return {
                title:
                    'Mengganti institusi',

                description:
                    'Sedang mengganti institusi aktif.',
            };

        case 'workspace-switch':
            return {
                title:
                    'Mengganti Workspace',

                description:
                    'Sedang mengganti Workspace aktif.',
            };

        case 'workspace-recovery':
            return {
                title:
                    'Memulihkan Workspace',

                description:
                    'Sedang memulihkan Workspace ke konteks yang aman.',
            };

        case 'capability-load':
            return {
                title:
                    'Memeriksa akses',

                description:
                    'Sedang memuat informasi akses halaman.',
            };

        case 'membership-discovery':
        case 'workspace-discovery':
        case undefined:
            return {
                title:
                    'Menyiapkan halaman',

                description:
                    pendingMessage(
                        source,
                    ),
            };
    }
}

function unavailableMessage(
    source:
        ProtectedRouteUnavailableSource,
): string {
    switch (
        source
    ) {
        case 'authentication':
            return 'Status autentikasi belum dapat dipastikan.';

        case 'membership':
            return 'Konteks Membership belum dapat dimuat.';

        case 'workspace':
            return 'Workspace belum dapat disiapkan.';

        case 'authorization':
            return 'Informasi otorisasi belum dapat dimuat.';
    }
}

interface StatePageProps {
    readonly eyebrow:
        string;

    readonly title:
        string;

    readonly description:
        string;

    readonly action?:
        {
            readonly label:
                string;

            readonly onClick:
                () => void;
        };
}

function StatePage({
    eyebrow,
    title,
    description,
    action,
}: StatePageProps) {
    return (
        <main className="min-h-screen bg-slate-950 text-slate-100">
            <section className="mx-auto flex min-h-screen max-w-3xl items-center px-6 py-16">
                <div className="space-y-4">
                    <p className="text-sm font-semibold uppercase tracking-[0.2em] text-slate-400">
                        {eyebrow}
                    </p>

                    <h1 className="text-3xl font-semibold tracking-tight">
                        {title}
                    </h1>

                    <p className="max-w-xl leading-7 text-slate-300">
                        {description}
                    </p>

                    {
                        action === undefined
                            ? null
                            : (
                                <button
                                    className="rounded-lg bg-slate-100 px-4 py-2.5 font-semibold text-slate-950 transition hover:bg-white"
                                    onClick={
                                        action.onClick
                                    }
                                    type="button"
                                >
                                    {action.label}
                                </button>
                            )
                    }
                </div>
            </section>
        </main>
    );
}

export function ProtectedRouteStateView({
    decision,
    onRetryUnavailable,
}: ProtectedRouteStateViewProps) {
    switch (
        decision.status
    ) {
        case 'pending': {
            const presentation =
                pendingPresentation(
                    decision.source,
                    decision.phase,
                );

            return (
                <StatePage
                    eyebrow="EduCore"
                    title={
                        presentation.title
                    }
                    description={
                        presentation.description
                    }
                />
            );
        }

        case 'membership-required':
            return (
                <StatePage
                    eyebrow="Membership"
                    title="Pilih Membership"
                    description="Pilih Membership yang akan digunakan sebelum melanjutkan ke aplikasi."
                />
            );

        case 'membership-empty':
            return (
                <StatePage
                    eyebrow="Membership"
                    title="Membership belum tersedia"
                    description="Akun ini belum mempunyai Membership aktif yang dapat digunakan untuk membuka aplikasi."
                />
            );

        case 'unavailable':
            return (
                <StatePage
                    eyebrow="EduCore"
                    title="Konteks belum tersedia"
                    description={
                        unavailableMessage(
                            decision.source,
                        )
                    }
                    {...(
                        onRetryUnavailable
                            === undefined
                            ? {}
                            : {
                                action: {
                                    label:
                                        'Coba lagi',

                                    onClick:
                                        onRetryUnavailable,
                                },
                            }
                    )}
                />
            );

        case 'context-required':
            return (
                <StatePage
                    eyebrow="Workspace"
                    title={
                        decision.requiredContext
                            === 'organizational'
                            ? 'Pilih Workspace organisasi'
                            : 'Gunakan Workspace Tenant'
                    }
                    description={
                        decision.requiredContext
                            === 'organizational'
                            ? 'Halaman ini memerlukan Workspace organisasi yang dipilih secara eksplisit.'
                            : 'Halaman ini menggunakan konteks Tenant dan tidak dapat dijalankan dari Workspace organisasi saat ini.'
                    }
                />
            );

        case 'denied':
            return (
                <StatePage
                    eyebrow="403"
                    title="Akses ditolak"
                    description="Anda tidak mempunyai permission yang diperlukan untuk membuka halaman ini."
                />
            );
    }
}
