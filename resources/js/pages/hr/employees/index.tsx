import { Head, Link, router } from '@inertiajs/react';
import {
    ChartColumn,
    ChevronLeft,
    ChevronRight,
    Download,
    FileUp,
    Search,
    UserPlus,
} from 'lucide-react';
import type { FormEvent } from 'react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type EmployeeRow = {
    id: string;
    name: string;
    employee_number: string | null;
    status: 'AKTIF' | 'BERHENTI';
    status_label: string;
    employment_type: string | null;
    classification: string | null;
    primary_placement: string | null;
    other_placements: number;
};

type Option = { value: string; label: string };

type Filters = {
    q: string;
    status: string;
    lembaga: string;
    jenis: string;
    status_kepegawaian: string;
    jabatan: string;
};

type Props = {
    employees: {
        data: EmployeeRow[];
        current_page: number;
        last_page: number;
        total: number;
        from: number | null;
        to: number | null;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    filters: Filters;
    options: {
        organizations: Option[];
        employmentTypes: Option[];
        classifications: Option[];
        positions: Option[];
    };
    canCreate: boolean;
};

const BASE_URL = '/pegawai';

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';

const STATUS_OPTIONS: Option[] = [
    { value: 'aktif', label: 'Aktif' },
    { value: 'berhenti', label: 'Berhenti' },
    { value: 'semua', label: 'Semua' },
];

/**
 * Daftar pegawai (PRD-001 §6). Data sudah disaring server sesuai cakupan pengguna (§5.3).
 */
export default function Employees({
    employees,
    filters,
    options,
    canCreate,
}: Props) {
    const applyFilters = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        const data = new FormData(event.currentTarget);
        const query: Record<string, string> = {};

        for (const [key, value] of data.entries()) {
            if (typeof value === 'string' && value.trim() !== '') {
                query[key] = value.trim();
            }
        }

        // "aktif" adalah nilai bawaan; tidak perlu ditulis di URL.
        if (query.status === 'aktif') {
            delete query.status;
        }

        router.get(BASE_URL, query, { preserveState: true, replace: true });
    };

    const isFiltered =
        filters.q !== '' ||
        filters.status !== 'aktif' ||
        filters.lembaga !== '' ||
        filters.jenis !== '' ||
        filters.status_kepegawaian !== '' ||
        filters.jabatan !== '';

    return (
        <>
            <Head title="Pegawai" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <Heading
                        title="Pegawai"
                        description="Pegawai di lembaga yang bisa Anda lihat dari lembaga kerja ini."
                    />

                    {/* Grid otomatis: tombol turun ke baris berikutnya di layar sempit. */}
                    <div className="grid grid-cols-[repeat(auto-fit,minmax(10rem,1fr))] gap-2 sm:flex">
                        <Button variant="outline" asChild>
                            <Link href={`${BASE_URL}/laporan`}>
                                <ChartColumn />
                                Laporan
                            </Link>
                        </Button>
                        {/* Unduhan memakai saringan yang sedang dipakai (HR-6a). */}
                        <Button variant="outline" asChild>
                            <a
                                href={`${BASE_URL}/unduh?${new URLSearchParams(filters).toString()}`}
                            >
                                <Download />
                                Unduh .xlsx
                            </a>
                        </Button>
                        {canCreate && (
                            <>
                                <Button variant="outline" asChild>
                                    <Link href={`${BASE_URL}/impor`}>
                                        <FileUp />
                                        Impor
                                    </Link>
                                </Button>
                                <Button asChild>
                                    <Link href={`${BASE_URL}/tambah`}>
                                        <UserPlus />
                                        Tambah pegawai
                                    </Link>
                                </Button>
                            </>
                        )}
                    </div>
                </div>

                {/* Grid otomatis: kolom saringan turun ke baris berikutnya di layar sempit. */}
                <form
                    key={JSON.stringify(filters)}
                    onSubmit={applyFilters}
                    className="grid grid-cols-[repeat(auto-fit,minmax(12rem,1fr))] items-end gap-3 rounded-xl border p-4"
                >
                    <div className="grid gap-1.5">
                        <Label htmlFor="q">Cari</Label>
                        <Input
                            id="q"
                            name="q"
                            type="search"
                            placeholder="Nama atau nomor induk"
                            defaultValue={filters.q}
                            maxLength={100}
                        />
                    </div>

                    <FilterSelect
                        id="lembaga"
                        label="Lembaga"
                        allLabel="Semua lembaga"
                        options={options.organizations}
                        value={filters.lembaga}
                    />
                    <FilterSelect
                        id="jabatan"
                        label="Jabatan"
                        allLabel="Semua jabatan"
                        options={options.positions}
                        value={filters.jabatan}
                    />
                    <FilterSelect
                        id="jenis"
                        label="Jenis pegawai"
                        allLabel="Semua jenis"
                        options={options.employmentTypes}
                        value={filters.jenis}
                    />
                    <FilterSelect
                        id="status_kepegawaian"
                        label="Status kepegawaian"
                        allLabel="Semua status"
                        options={options.classifications}
                        value={filters.status_kepegawaian}
                    />

                    <div className="grid gap-1.5">
                        <Label htmlFor="status">Aktif/berhenti</Label>
                        <select
                            id="status"
                            name="status"
                            className={selectClass}
                            defaultValue={filters.status}
                        >
                            {STATUS_OPTIONS.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div className="flex gap-2">
                        <Button type="submit" className="flex-1">
                            <Search />
                            Terapkan
                        </Button>
                        {isFiltered && (
                            <Button variant="outline" asChild>
                                <Link href={BASE_URL}>Reset</Link>
                            </Button>
                        )}
                    </div>
                </form>

                {employees.data.length === 0 ? (
                    <p className="rounded-xl border p-6 text-center text-sm text-muted-foreground">
                        {isFiltered
                            ? 'Tidak ada pegawai yang cocok dengan saringan ini.'
                            : 'Belum ada pegawai yang bisa Anda lihat di lembaga kerja ini.'}
                    </p>
                ) : (
                    <>
                        {/* Desktop: tabel. */}
                        <div className="hidden overflow-hidden rounded-xl border md:block">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left text-muted-foreground">
                                    <tr>
                                        <th className="px-4 py-2 font-medium">
                                            Nama
                                        </th>
                                        <th className="px-4 py-2 font-medium">
                                            Penempatan
                                        </th>
                                        <th className="px-4 py-2 font-medium">
                                            Kepegawaian
                                        </th>
                                        <th className="px-4 py-2 font-medium">
                                            Status
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {employees.data.map((employee) => (
                                        <tr
                                            key={employee.id}
                                            className="hover:bg-muted/30"
                                        >
                                            <td className="px-4 py-3">
                                                <Link
                                                    href={`${BASE_URL}/${employee.id}`}
                                                    className="font-medium hover:underline"
                                                >
                                                    {employee.name}
                                                </Link>
                                                {employee.employee_number && (
                                                    <p className="font-mono text-xs text-muted-foreground">
                                                        {
                                                            employee.employee_number
                                                        }
                                                    </p>
                                                )}
                                            </td>
                                            <td className="px-4 py-3">
                                                <PlacementText
                                                    employee={employee}
                                                />
                                            </td>
                                            <td className="px-4 py-3">
                                                <EmploymentText
                                                    employee={employee}
                                                />
                                            </td>
                                            <td className="px-4 py-3">
                                                <StatusBadge
                                                    employee={employee}
                                                />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        {/* HP: kartu. */}
                        <ul className="grid gap-2 md:hidden">
                            {employees.data.map((employee) => (
                                <li key={employee.id}>
                                    <Link
                                        href={`${BASE_URL}/${employee.id}`}
                                        className="block space-y-1.5 rounded-xl border p-4 hover:bg-muted/30"
                                    >
                                        <div className="flex items-start justify-between gap-2">
                                            <div className="min-w-0">
                                                <p className="font-medium break-words">
                                                    {employee.name}
                                                </p>
                                                {employee.employee_number && (
                                                    <p className="font-mono text-xs text-muted-foreground">
                                                        {
                                                            employee.employee_number
                                                        }
                                                    </p>
                                                )}
                                            </div>
                                            <StatusBadge employee={employee} />
                                        </div>
                                        <PlacementText employee={employee} />
                                        <EmploymentText employee={employee} />
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </>
                )}

                <Pagination employees={employees} />
            </div>
        </>
    );
}

function FilterSelect({
    id,
    label,
    allLabel,
    options,
    value,
}: {
    id: string;
    label: string;
    allLabel: string;
    options: Option[];
    value: string;
}) {
    return (
        <div className="grid gap-1.5">
            <Label htmlFor={id}>{label}</Label>
            <select
                id={id}
                name={id}
                className={selectClass}
                defaultValue={value}
            >
                <option value="">{allLabel}</option>
                {options.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
        </div>
    );
}

function PlacementText({ employee }: { employee: EmployeeRow }) {
    if (!employee.primary_placement) {
        return (
            <p className="text-sm text-muted-foreground">
                Belum ada penempatan
            </p>
        );
    }

    return (
        <p className="text-sm">
            {employee.primary_placement}
            {employee.other_placements > 0 && (
                <span className="text-muted-foreground">
                    {' '}
                    +{employee.other_placements} lainnya
                </span>
            )}
        </p>
    );
}

function EmploymentText({ employee }: { employee: EmployeeRow }) {
    const parts = [employee.employment_type, employee.classification].filter(
        (part): part is string => part !== null,
    );

    return (
        <p className="text-sm text-muted-foreground">
            {parts.length > 0 ? parts.join(' · ') : '–'}
        </p>
    );
}

function StatusBadge({ employee }: { employee: EmployeeRow }) {
    return (
        <Badge variant={employee.status === 'AKTIF' ? 'secondary' : 'outline'}>
            {employee.status_label}
        </Badge>
    );
}

function Pagination({ employees }: { employees: Props['employees'] }) {
    if (employees.total === 0) {
        return null;
    }

    return (
        <nav
            aria-label="Halaman daftar pegawai"
            className="flex flex-wrap items-center justify-between gap-3"
        >
            <p className="text-sm text-muted-foreground">
                {employees.from}–{employees.to} dari {employees.total} pegawai
            </p>
            {employees.last_page > 1 && (
                <div className="flex gap-2">
                    <PageLink href={employees.prev_page_url} label="Sebelumnya">
                        <ChevronLeft />
                        Sebelumnya
                    </PageLink>
                    <PageLink href={employees.next_page_url} label="Berikutnya">
                        Berikutnya
                        <ChevronRight />
                    </PageLink>
                </div>
            )}
        </nav>
    );
}

function PageLink({
    href,
    label,
    children,
}: {
    href: string | null;
    label: string;
    children: React.ReactNode;
}) {
    if (!href) {
        return (
            <Button variant="outline" size="sm" disabled aria-label={label}>
                {children}
            </Button>
        );
    }

    return (
        <Button variant="outline" size="sm" asChild>
            <Link href={href} preserveScroll aria-label={label}>
                {children}
            </Link>
        </Button>
    );
}
