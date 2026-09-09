import {
    useState,
} from 'react';
import {
    Link,
} from 'react-router';

import {
    useWorkspaceEmployeesQuery,
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

export function HrWorkforcePage() {
    const [
        page,
        setPage,
    ] = useState(1);

    const query =
        useWorkspaceEmployeesQuery(
            {
                page,
            },
        );

    return (
        <section
            aria-labelledby="hr-workforce-heading"
            className="space-y-4"
        >
            <div>
                <h1
                    id="hr-workforce-heading"
                    className="text-xl font-semibold"
                >
                    Daftar Pegawai
                </h1>

                <p className="text-sm text-muted-foreground">
                    Pegawai yang terlihat pada workspace organisasi Anda saat ini.
                </p>
            </div>

            {
                query.status === 'pending'
                    ? (
                        <p
                            role="status"
                            className="text-sm text-muted-foreground"
                        >
                            Memuat daftar pegawai…
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
                            Gagal memuat daftar pegawai. Coba muat ulang halaman ini.
                        </div>
                    )
                    : null
            }

            {
                query.status === 'success'
                    ? (
                        query.data.employees.length === 0
                            ? (
                                <p className="text-sm text-muted-foreground">
                                    Belum ada pegawai yang terlihat di workspace ini.
                                </p>
                            )
                            : (
                                <>
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>
                                                    Nama
                                                </TableHead>
                                                <TableHead>
                                                    NIP
                                                </TableHead>
                                                <TableHead>
                                                    Jabatan
                                                </TableHead>
                                                <TableHead>
                                                    Aksi
                                                </TableHead>
                                            </TableRow>
                                        </TableHeader>

                                        <TableBody>
                                            {
                                                query.data.employees.map(
                                                    (
                                                        employee,
                                                    ) => (
                                                        <TableRow
                                                            key={
                                                                employee.id
                                                            }
                                                        >
                                                            <TableCell className="font-medium">
                                                                {
                                                                    employee.nama
                                                                }
                                                            </TableCell>

                                                            <TableCell>
                                                                {
                                                                    employee.nip
                                                                    ?? (
                                                                        <span className="text-muted-foreground">
                                                                            —
                                                                        </span>
                                                                    )
                                                                }
                                                            </TableCell>

                                                            <TableCell>
                                                                <Badge variant="secondary">
                                                                    {
                                                                        jabatanLabel(
                                                                            employee.jabatan,
                                                                        )
                                                                    }
                                                                </Badge>
                                                            </TableCell>

                                                            <TableCell>
                                                                <Button
                                                                    asChild
                                                                    variant="outline"
                                                                    size="sm"
                                                                >
                                                                    <Link
                                                                        to={
                                                                            `/hr/workforce/${employee.id}`
                                                                        }
                                                                    >
                                                                        Lihat Detail
                                                                    </Link>
                                                                </Button>
                                                            </TableCell>
                                                        </TableRow>
                                                    ),
                                                )
                                            }
                                        </TableBody>
                                    </Table>

                                    <div className="flex items-center justify-between pt-2">
                                        <p className="text-sm text-muted-foreground">
                                            Halaman
                                            {' '}
                                            {
                                                query.data.currentPage
                                            }
                                            {' '}
                                            dari
                                            {' '}
                                            {
                                                query.data.lastPage
                                            }
                                            {' '}
                                            ({
                                                query.data.total
                                            }
                                            {' '}
                                            pegawai)
                                        </p>

                                        <div className="flex gap-2">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                disabled={
                                                    query.data.currentPage <= 1
                                                }
                                                onClick={
                                                    () =>
                                                        setPage(
                                                            (
                                                                current,
                                                            ) =>
                                                                Math.max(
                                                                    1,
                                                                    current - 1,
                                                                ),
                                                        )
                                                }
                                            >
                                                Sebelumnya
                                            </Button>

                                            <Button
                                                variant="outline"
                                                size="sm"
                                                disabled={
                                                    query.data.currentPage
                                                        >= query.data.lastPage
                                                }
                                                onClick={
                                                    () =>
                                                        setPage(
                                                            (
                                                                current,
                                                            ) =>
                                                                current + 1,
                                                        )
                                                }
                                            >
                                                Berikutnya
                                            </Button>
                                        </div>
                                    </div>
                                </>
                            )
                    )
                    : null
            }
        </section>
    );
}
