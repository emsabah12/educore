import { Head, Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Search, UserPlus } from 'lucide-react';
import type { FormEvent } from 'react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type EnrollmentSummary = {
    organization: string;
    student_number: string | null;
    open: boolean;
    end_reason: string | null;
};

type StudentRow = {
    id: string;
    name: string;
    nisn: string | null;
    status: 'AKTIF' | 'KELUAR';
    status_label: string;
    enrollments: EnrollmentSummary[];
    other_active: number;
};

type Option = { value: string; label: string };

type Filters = {
    q: string;
    status: string;
    lembaga: string;
};

type Props = {
    students: {
        data: StudentRow[];
        current_page: number;
        last_page: number;
        total: number;
        from: number | null;
        to: number | null;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    filters: Filters;
    options: { organizations: Option[] };
    canCreate: boolean;
};

const BASE_URL = '/siswa';

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';

const STATUS_OPTIONS: Option[] = [
    { value: 'aktif', label: 'Aktif' },
    { value: 'keluar', label: 'Keluar/lulus' },
    { value: 'semua', label: 'Semua' },
];

/**
 * Daftar siswa (PRD-002 §6, AC-1c-a). Data sudah disaring server sesuai cakupan pengguna (§5.3).
 */
export default function Students({
    students,
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
        filters.lembaga !== '';

    return (
        <>
            <Head title="Siswa" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <Heading
                        title="Siswa"
                        description="Siswa/santri di lembaga yang bisa Anda lihat dari lembaga kerja ini."
                    />

                    {canCreate && (
                        <Button asChild>
                            <Link href={`${BASE_URL}/tambah`}>
                                <UserPlus />
                                Tambah siswa
                            </Link>
                        </Button>
                    )}
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
                            placeholder="Nama, NISN, atau nomor induk"
                            defaultValue={filters.q}
                            maxLength={100}
                        />
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="lembaga">Lembaga</Label>
                        <select
                            id="lembaga"
                            name="lembaga"
                            className={selectClass}
                            defaultValue={filters.lembaga}
                        >
                            <option value="">Semua lembaga</option>
                            {options.organizations.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="status">Status</Label>
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

                {students.data.length === 0 ? (
                    <p className="rounded-xl border p-6 text-center text-sm text-muted-foreground">
                        {isFiltered
                            ? 'Tidak ada siswa yang cocok dengan saringan ini.'
                            : 'Belum ada siswa yang bisa Anda lihat di lembaga kerja ini.'}
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
                                            Lembaga
                                        </th>
                                        <th className="px-4 py-2 font-medium">
                                            Status
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {students.data.map((student) => (
                                        <tr
                                            key={student.id}
                                            className="hover:bg-muted/30"
                                        >
                                            <td className="px-4 py-3">
                                                <Link
                                                    href={`${BASE_URL}/${student.id}`}
                                                    className="font-medium hover:underline"
                                                >
                                                    {student.name}
                                                </Link>
                                                {student.nisn && (
                                                    <p className="font-mono text-xs text-muted-foreground">
                                                        NISN {student.nisn}
                                                    </p>
                                                )}
                                            </td>
                                            <td className="px-4 py-3">
                                                <EnrollmentText
                                                    student={student}
                                                />
                                            </td>
                                            <td className="px-4 py-3">
                                                <StatusBadge
                                                    student={student}
                                                />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        {/* HP: kartu. */}
                        <ul className="grid gap-2 md:hidden">
                            {students.data.map((student) => (
                                <li key={student.id}>
                                    <Link
                                        href={`${BASE_URL}/${student.id}`}
                                        className="block space-y-1.5 rounded-xl border p-4 hover:bg-muted/30"
                                    >
                                        <div className="flex items-start justify-between gap-2">
                                            <div className="min-w-0">
                                                <p className="font-medium break-words">
                                                    {student.name}
                                                </p>
                                                {student.nisn && (
                                                    <p className="font-mono text-xs text-muted-foreground">
                                                        NISN {student.nisn}
                                                    </p>
                                                )}
                                            </div>
                                            <StatusBadge student={student} />
                                        </div>
                                        <EnrollmentText student={student} />
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </>
                )}

                <Pagination students={students} />
            </div>
        </>
    );
}

/** Pendaftaran di lembaga yang terlihat; yang aktif lebih dulu. */
function EnrollmentText({ student }: { student: StudentRow }) {
    if (student.enrollments.length === 0) {
        return <p className="text-sm text-muted-foreground">–</p>;
    }

    return (
        <div className="space-y-0.5 text-sm">
            {student.enrollments.map((enrollment, index) => (
                <p
                    key={index}
                    className={enrollment.open ? '' : 'text-muted-foreground'}
                >
                    {enrollment.organization}
                    {enrollment.student_number && (
                        <span className="font-mono text-xs text-muted-foreground">
                            {' '}
                            · {enrollment.student_number}
                        </span>
                    )}
                    {!enrollment.open && (
                        <span> — {enrollment.end_reason ?? 'keluar'}</span>
                    )}
                </p>
            ))}
            {student.other_active > 0 && (
                <p className="text-xs text-muted-foreground">
                    Juga aktif di {student.other_active} lembaga lain
                </p>
            )}
        </div>
    );
}

function StatusBadge({ student }: { student: StudentRow }) {
    return (
        <Badge variant={student.status === 'AKTIF' ? 'secondary' : 'outline'}>
            {student.status_label}
        </Badge>
    );
}

function Pagination({ students }: { students: Props['students'] }) {
    if (students.total === 0) {
        return null;
    }

    return (
        <nav
            aria-label="Halaman daftar siswa"
            className="flex flex-wrap items-center justify-between gap-3"
        >
            <p className="text-sm text-muted-foreground">
                {students.from}–{students.to} dari {students.total} siswa
            </p>
            {students.last_page > 1 && (
                <div className="flex gap-2">
                    <PageLink href={students.prev_page_url} label="Sebelumnya">
                        <ChevronLeft />
                        Sebelumnya
                    </PageLink>
                    <PageLink href={students.next_page_url} label="Berikutnya">
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
