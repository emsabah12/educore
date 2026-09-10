import {
    useState,
} from 'react';
import {
    Link,
    useParams,
} from 'react-router';

import {
    useActivateEmploymentMutation,
    useCancelEmploymentMutation,
    useCreateEmploymentMutation,
    useEndEmploymentMutation,
} from '@/modules/hr/api/use-employment-mutations';
import {
    useWorkspaceEmployeeDetailQuery,
    type WorkspaceEmployeeDetail,
} from '@/modules/hr/api/use-workspace-employees-query';
import type {
    BrowserApiFailure,
} from '@/platform/api';
import {
    Badge,
    Button,
    Input,
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/shared/ui';

const JABATAN_LABEL: Record<string, string> = {
    GURU: 'Guru',
    KEPALA_SEKOLAH: 'Kepala Sekolah',
    STAFF: 'Staf',
};

function jabatanLabel(
    jabatan: string,
): string {
    return (
        JABATAN_LABEL[jabatan]
        ?? jabatan
    );
}

const EMPLOYMENT_STATUS_VARIANT: Record<
    string,
    'success' | 'warning' | 'secondary'
> = {
    ACTIVE: 'success',
    PLANNED: 'warning',
    ENDED: 'secondary',
    CANCELLED: 'secondary',
};

const EMPLOYMENT_STATUS_LABEL: Record<string, string> = {
    ACTIVE: 'Aktif',
    PLANNED: 'Direncanakan',
    ENDED: 'Berakhir',
    CANCELLED: 'Dibatalkan',
};

type EmploymentSummary =
    WorkspaceEmployeeDetail['employments'][number];

function transitionErrorMessage(
    error: BrowserApiFailure,
): string {
    if (
        error.kind === 'response'
        && error.error.code === 'EMPLOYMENT_LIFECYCLE_CONFLICT'
    ) {
        return 'Status employment ini sudah berubah — muat ulang halaman untuk melihat status terkini.';
    }

    return 'Gagal memproses aksi. Coba lagi.';
}

function EmploymentActionsCell({
    employeeId,
    employment,
}: {
    employeeId: string;
    employment: EmploymentSummary;
}) {
    const activateMutation =
        useActivateEmploymentMutation();

    const cancelMutation =
        useCancelEmploymentMutation();

    const endMutation =
        useEndEmploymentMutation();

    const [
        isEnding,
        setIsEnding,
    ] = useState(false);

    const [
        endDate,
        setEndDate,
    ] = useState('');

    if (employment.status === 'PLANNED') {
        return (
            <div className="flex flex-col items-start gap-1">
                <div className="flex gap-2">
                    <Button
                        size="sm"
                        disabled={
                            activateMutation.isPending
                        }
                        onClick={
                            () =>
                                activateMutation.mutate(
                                    {
                                        employmentId: employment.id,
                                        employeeId,
                                    },
                                )
                        }
                    >
                        {
                            activateMutation.isPending
                                ? 'Mengaktifkan…'
                                : 'Aktifkan'
                        }
                    </Button>

                    <Button
                        variant="outline"
                        size="sm"
                        disabled={
                            cancelMutation.isPending
                        }
                        onClick={
                            () =>
                                cancelMutation.mutate(
                                    {
                                        employmentId: employment.id,
                                        employeeId,
                                    },
                                )
                        }
                    >
                        {
                            cancelMutation.isPending
                                ? 'Membatalkan…'
                                : 'Batalkan'
                        }
                    </Button>
                </div>

                {
                    activateMutation.isError
                        ? (
                            <p
                                role="alert"
                                className="text-xs text-destructive"
                            >
                                {
                                    transitionErrorMessage(
                                        activateMutation.error,
                                    )
                                }
                            </p>
                        )
                        : null
                }

                {
                    cancelMutation.isError
                        ? (
                            <p
                                role="alert"
                                className="text-xs text-destructive"
                            >
                                {
                                    transitionErrorMessage(
                                        cancelMutation.error,
                                    )
                                }
                            </p>
                        )
                        : null
                }
            </div>
        );
    }

    if (employment.status === 'ACTIVE') {
        if (! isEnding) {
            return (
                <Button
                    variant="outline"
                    size="sm"
                    onClick={
                        () =>
                            setIsEnding(
                                true,
                            )
                    }
                >
                    Akhiri
                </Button>
            );
        }

        return (
            <div className="flex flex-col items-start gap-1">
                <div className="flex items-center gap-2">
                    <Input
                        type="date"
                        aria-label={
                            `Tanggal akhir untuk ${employment.employment_type ?? 'employment ini'}`
                        }
                        value={endDate}
                        onChange={
                            (
                                event,
                            ) =>
                                setEndDate(
                                    event.target.value,
                                )
                        }
                        className="h-8 w-36 text-xs"
                    />

                    <Button
                        size="sm"
                        disabled={
                            endDate === ''
                            || endMutation.isPending
                        }
                        onClick={
                            () =>
                                endMutation.mutate(
                                    {
                                        employmentId: employment.id,
                                        employeeId,
                                        endDate,
                                    },
                                    {
                                        onSuccess: () => {
                                            setIsEnding(
                                                false,
                                            );
                                        },
                                    },
                                )
                        }
                    >
                        {
                            endMutation.isPending
                                ? 'Menyimpan…'
                                : 'Konfirmasi'
                        }
                    </Button>

                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={
                            () => {
                                setIsEnding(
                                    false,
                                );
                                setEndDate(
                                    '',
                                );
                            }
                        }
                    >
                        Batal
                    </Button>
                </div>

                {
                    endMutation.isError
                        ? (
                            <p
                                role="alert"
                                className="text-xs text-destructive"
                            >
                                {
                                    transitionErrorMessage(
                                        endMutation.error,
                                    )
                                }
                            </p>
                        )
                        : null
                }
            </div>
        );
    }

    return (
        <span className="text-xs text-muted-foreground">
            —
        </span>
    );
}

function CreateEmploymentForm({
    employeeId,
}: {
    employeeId: string;
}) {
    const createMutation =
        useCreateEmploymentMutation();

    const [
        isOpen,
        setIsOpen,
    ] = useState(false);

    const [
        startDate,
        setStartDate,
    ] = useState('');

    if (! isOpen) {
        return (
            <Button
                size="sm"
                onClick={
                    () =>
                        setIsOpen(
                            true,
                        )
                }
            >
                + Tambah Employment
            </Button>
        );
    }

    return (
        <div className="flex flex-col items-start gap-1 rounded-md border p-3">
            <label
                htmlFor="new-employment-start-date"
                className="text-xs font-medium"
            >
                Tanggal Mulai
            </label>

            <div className="flex items-center gap-2">
                <Input
                    id="new-employment-start-date"
                    type="date"
                    value={startDate}
                    onChange={
                        (
                            event,
                        ) =>
                            setStartDate(
                                event.target.value,
                            )
                    }
                    className="h-8 w-36 text-xs"
                />

                <Button
                    size="sm"
                    disabled={
                        startDate === ''
                        || createMutation.isPending
                    }
                    onClick={
                        () =>
                            createMutation.mutate(
                                {
                                    employeeId,
                                    startDate,
                                },
                                {
                                    onSuccess: () => {
                                        setIsOpen(
                                            false,
                                        );
                                        setStartDate(
                                            '',
                                        );
                                    },
                                },
                            )
                    }
                >
                    {
                        createMutation.isPending
                            ? 'Menyimpan…'
                            : 'Buat'
                    }
                </Button>

                <Button
                    variant="ghost"
                    size="sm"
                    onClick={
                        () => {
                            setIsOpen(
                                false,
                            );
                            setStartDate(
                                '',
                            );
                        }
                    }
                >
                    Batal
                </Button>
            </div>

            {
                createMutation.isError
                    ? (
                        <p
                            role="alert"
                            className="text-xs text-destructive"
                        >
                            Gagal membuat employment baru. Coba lagi.
                        </p>
                    )
                    : null
            }
        </div>
    );
}

export function HrEmployeeDetailPage() {
    const {
        employeeId,
    } = useParams<{
        employeeId: string;
    }>();

    const query =
        useWorkspaceEmployeeDetailQuery(
            employeeId
            ?? null,
        );

    return (
        <section
            aria-labelledby="hr-employee-detail-heading"
            className="space-y-6"
        >
            <Button
                asChild
                variant="ghost"
                size="sm"
            >
                <Link to="/hr/workforce">
                    ← Kembali ke Daftar Pegawai
                </Link>
            </Button>

            {
                query.status === 'pending'
                    ? (
                        <p
                            role="status"
                            className="text-sm text-muted-foreground"
                        >
                            Memuat detail pegawai…
                        </p>
                    )
                    : null
            }

            {
                query.status === 'error'
                    ? (
                        <div
                            role="alert"
                            className="rounded-md border border-destructive/50 bg-destructive/10 p-4 text-sm text-destructive"
                        >
                            {
                                query.error.kind === 'response'
                                && query.error.status === 404
                                    ? 'Pegawai tidak ditemukan, atau berada di luar workspace Anda saat ini.'
                                    : 'Gagal memuat detail pegawai. Coba muat ulang halaman ini.'
                            }
                        </div>
                    )
                    : null
            }

            {
                query.status === 'success'
                    ? (
                        <>
                            <div>
                                <h1
                                    id="hr-employee-detail-heading"
                                    className="text-xl font-semibold"
                                >
                                    {
                                        query.data.nama
                                    }
                                </h1>

                                <div className="mt-2 flex items-center gap-3 text-sm text-muted-foreground">
                                    <span>
                                        NIP:
                                        {' '}
                                        {
                                            query.data.nip
                                            ?? '—'
                                        }
                                    </span>

                                    <Badge variant="secondary">
                                        {
                                            jabatanLabel(
                                                query.data.jabatan,
                                            )
                                        }
                                    </Badge>
                                </div>
                            </div>

                            <div className="space-y-3">
                                <div className="flex items-center justify-between">
                                    <h2 className="text-sm font-semibold">
                                        Riwayat Employment
                                    </h2>

                                    <CreateEmploymentForm
                                        employeeId={
                                            employeeId
                                            ?? ''
                                        }
                                    />
                                </div>

                                {
                                    query.data.employments.length === 0
                                        ? (
                                            <p className="text-sm text-muted-foreground">
                                                Belum ada riwayat employment.
                                            </p>
                                        )
                                        : (
                                            <Table>
                                                <TableHeader>
                                                    <TableRow>
                                                        <TableHead>
                                                            Jenis
                                                        </TableHead>
                                                        <TableHead>
                                                            Status
                                                        </TableHead>
                                                        <TableHead>
                                                            Mulai
                                                        </TableHead>
                                                        <TableHead>
                                                            Berakhir
                                                        </TableHead>
                                                        <TableHead>
                                                            Aksi
                                                        </TableHead>
                                                    </TableRow>
                                                </TableHeader>

                                                <TableBody>
                                                    {
                                                        query.data.employments.map(
                                                            (
                                                                employment,
                                                            ) => (
                                                                <TableRow
                                                                    key={
                                                                        employment.id
                                                                    }
                                                                >
                                                                    <TableCell>
                                                                        {
                                                                            employment.employment_type
                                                                            ?? (
                                                                                <span className="text-muted-foreground">
                                                                                    —
                                                                                </span>
                                                                            )
                                                                        }
                                                                    </TableCell>

                                                                    <TableCell>
                                                                        <Badge
                                                                            variant={
                                                                                EMPLOYMENT_STATUS_VARIANT[employment.status]
                                                                                ?? 'secondary'
                                                                            }
                                                                        >
                                                                            {
                                                                                EMPLOYMENT_STATUS_LABEL[employment.status]
                                                                                ?? employment.status
                                                                            }
                                                                        </Badge>
                                                                    </TableCell>

                                                                    <TableCell>
                                                                        {
                                                                            employment.start_date
                                                                        }
                                                                    </TableCell>

                                                                    <TableCell>
                                                                        {
                                                                            employment.end_date
                                                                            ?? (
                                                                                <span className="text-muted-foreground">
                                                                                    —
                                                                                </span>
                                                                            )
                                                                        }
                                                                    </TableCell>

                                                                    <TableCell>
                                                                        <EmploymentActionsCell
                                                                            employeeId={
                                                                                employeeId
                                                                                ?? ''
                                                                            }
                                                                            employment={
                                                                                employment
                                                                            }
                                                                        />
                                                                    </TableCell>
                                                                </TableRow>
                                                            ),
                                                        )
                                                    }
                                                </TableBody>
                                            </Table>
                                        )
                                }
                            </div>
                        </>
                    )
                    : null
            }
        </section>
    );
}