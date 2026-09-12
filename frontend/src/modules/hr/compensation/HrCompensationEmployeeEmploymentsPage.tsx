import {
    useState,
} from 'react';
import {
    Link,
    useLocation,
    useParams,
} from 'react-router';

import {
    useHrEmployeeEmploymentsQuery,
} from '@/modules/hr/compensation/api/use-hr-employee-employments-query';
import {
    Badge,
    Button,
    Pagination,
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/shared/ui';

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

interface EmployeeSearchNavigationState {
    readonly employeeName?: string;
}

export function HrCompensationEmployeeEmploymentsPage() {
    const {
        employeeId,
    } = useParams<{
        employeeId:
            string;
    }>();

    const resolvedEmployeeId =
        employeeId
        ?? '';

    const location =
        useLocation();

    /*
     * Tidak ada endpoint GET tunggal untuk satu Employee (cuma
     * index + store) — nama pegawai dibawa lewat location.state
     * dari HrCompensationEmployeeSearchPage (baris yang di-klik),
     * menghindari panggilan API tambahan cuma untuk judul halaman.
     * Kalau halaman ini dibuka langsung (refresh/bookmark), state
     * kosong dan judul jatuh ke fallback generik di bawah.
     */
    const employeeName =
        (
            location.state as
                | EmployeeSearchNavigationState
                | null
        )
            ?.employeeName
        ?? null;

    const [
        page,
        setPage,
    ] = useState(1);

    const query =
        useHrEmployeeEmploymentsQuery(
            resolvedEmployeeId,
            page,
        );

    return (
        <section
            aria-labelledby="hr-compensation-employments-heading"
            className="space-y-4"
        >
            <Button
                asChild
                variant="ghost"
                size="sm"
            >
                <Link to="/hr/compensation">
                    ← Kembali ke Pencarian Pegawai
                </Link>
            </Button>

            <div>
                <h1
                    id="hr-compensation-employments-heading"
                    className="text-xl font-semibold"
                >
                    {
                        employeeName
                        ?? 'Riwayat Employment Pegawai'
                    }
                </h1>

                <p className="mt-1 text-sm text-muted-foreground">
                    Pilih salah satu Employment untuk mengelola kompensasi
                    dan benefit-nya.
                </p>
            </div>

            {
                query.status === 'pending'
                    ? (
                        <p
                            role="status"
                            className="text-sm text-muted-foreground"
                        >
                            Memuat riwayat employment…
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
                            Gagal memuat riwayat employment. Coba muat ulang
                            halaman ini.
                        </div>
                    )
                    : null
            }

            {
                query.status === 'success'
                    ? (
                        <>
                            {
                                query.data.items.length === 0
                                    ? (
                                        <p className="text-sm text-muted-foreground">
                                            Pegawai ini belum punya riwayat
                                            employment.
                                        </p>
                                    )
                                    : (
                                        <Table>
                                            <TableHeader>
                                                <TableRow>
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
                                                        <span className="sr-only">
                                                            Aksi
                                                        </span>
                                                    </TableHead>
                                                </TableRow>
                                            </TableHeader>

                                            <TableBody>
                                                {
                                                    query.data.items.map(
                                                        (
                                                            employment,
                                                        ) => (
                                                            <TableRow
                                                                key={
                                                                    employment.id
                                                                }
                                                            >
                                                                <TableCell>
                                                                    <Badge
                                                                        variant={
                                                                            EMPLOYMENT_STATUS_VARIANT[
                                                                                employment.status
                                                                            ]
                                                                        }
                                                                    >
                                                                        {
                                                                            EMPLOYMENT_STATUS_LABEL[
                                                                                employment.status
                                                                            ]
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
                                                                    <Button
                                                                        asChild
                                                                        size="sm"
                                                                    >
                                                                        <Link
                                                                            to={
                                                                                `/hr/compensation/employments/${employment.id}`
                                                                            }
                                                                            state={
                                                                                {
                                                                                    employeeName,
                                                                                    employmentStatus:
                                                                                        employment.status,
                                                                                    employmentStartDate:
                                                                                        employment.start_date,
                                                                                    employmentEndDate:
                                                                                        employment.end_date,
                                                                                }
                                                                            }
                                                                        >
                                                                            Pilih
                                                                        </Link>
                                                                    </Button>
                                                                </TableCell>
                                                            </TableRow>
                                                        ),
                                                    )
                                                }
                                            </TableBody>
                                        </Table>
                                    )
                            }

                            <Pagination
                                currentPage={
                                    query.data.currentPage
                                }
                                lastPage={
                                    query.data.lastPage
                                }
                                onPageChange={
                                    setPage
                                }
                            />
                        </>
                    )
                    : null
            }
        </section>
    );
}
