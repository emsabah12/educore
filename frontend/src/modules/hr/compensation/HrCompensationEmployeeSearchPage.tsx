import {
    useMemo,
    useState,
} from 'react';

import {
    useHrEmployeesDirectoryQuery,
    type HrEmployeeDirectoryEntry,
} from '@/modules/hr/compensation/api/use-hr-employees-directory-query';
import {
    Badge,
    Input,
    Pagination,
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

const EMPTY_EMPLOYEE_LIST: readonly HrEmployeeDirectoryEntry[] = [];

/*
 * Filter murni di sisi klien, HANYA pada baris yang sedang
 * termuat di halaman saat ini — GET /v1/hr/employees tidak
 * punya parameter pencarian server-side (cuma per_page/page),
 * jadi ini bukan pencarian menyeluruh lintas seluruh direktori
 * pegawai. Label di UI (lihat placeholder Input di bawah)
 * sengaja jujur soal keterbatasan ini.
 */
function filterEmployeesOnCurrentPage(
    employees: readonly HrEmployeeDirectoryEntry[],
    query: string,
): readonly HrEmployeeDirectoryEntry[] {
    const normalizedQuery =
        query.trim().toLowerCase();

    if (normalizedQuery === '') {
        return employees;
    }

    return employees.filter(
        (employee) =>
            employee.nama
                .toLowerCase()
                .includes(
                    normalizedQuery,
                )
            || (
                employee.nip
                ?.toLowerCase()
                .includes(
                    normalizedQuery,
                )
                ?? false
            ),
    );
}

export function HrCompensationEmployeeSearchPage() {
    const [
        page,
        setPage,
    ] = useState(1);

    const [
        filterQuery,
        setFilterQuery,
    ] = useState('');

    const query =
        useHrEmployeesDirectoryQuery(
            page,
        );

    const loadedEmployees =
        query.status === 'success'
            ? query.data.items
            : EMPTY_EMPLOYEE_LIST;

    const filteredEmployees =
        useMemo(
            () =>
                filterEmployeesOnCurrentPage(
                    loadedEmployees,
                    filterQuery,
                ),
            [
                loadedEmployees,
                filterQuery,
            ],
        );

    return (
        <section
            aria-labelledby="hr-compensation-search-heading"
            className="space-y-4"
        >
            <div>
                <h1
                    id="hr-compensation-search-heading"
                    className="text-xl font-semibold"
                >
                    Kompensasi & Benefit
                </h1>

                <p className="mt-1 text-sm text-muted-foreground">
                    Pilih pegawai untuk mengelola gaji, tunjangan, dan
                    kepesertaan benefit mereka.
                </p>
            </div>

            <Input
                type="search"
                placeholder="Filter nama/NIP pada halaman ini…"
                aria-label="Filter nama atau NIP pada halaman ini"
                value={
                    filterQuery
                }
                onChange={
                    (event) =>
                        setFilterQuery(
                            event.target.value,
                        )
                }
                className="max-w-sm"
            />

            {
                query.status === 'pending'
                    ? (
                        <p
                            role="status"
                            className="text-sm text-muted-foreground"
                        >
                            Memuat direktori pegawai…
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
                            Gagal memuat direktori pegawai. Coba muat ulang
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
                                filteredEmployees.length === 0
                                    ? (
                                        <p className="text-sm text-muted-foreground">
                                            {
                                                filterQuery.trim() === ''
                                                    ? 'Belum ada pegawai terdaftar.'
                                                    : 'Tidak ada pegawai yang cocok dengan filter pada halaman ini — coba ganti halaman atau kosongkan filter.'
                                            }
                                        </p>
                                    )
                                    : (
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
                                                </TableRow>
                                            </TableHeader>

                                            <TableBody>
                                                {
                                                    filteredEmployees.map(
                                                        (
                                                            employee,
                                                        ) => (
                                                            <TableRow
                                                                key={
                                                                    employee.employee_id
                                                                }
                                                            >
                                                                <TableCell>
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
