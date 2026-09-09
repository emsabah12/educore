import {
    Link,
    useParams,
} from 'react-router';

import {
    useWorkspaceEmployeeDetailQuery,
} from '@/modules/hr/api/use-workspace-employees-query';
import {
    Badge,
    Button,
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
                                <h2 className="text-sm font-semibold">
                                    Riwayat Employment
                                </h2>

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