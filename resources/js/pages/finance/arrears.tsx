import { Head, Link, router } from '@inertiajs/react';
import { Download } from 'lucide-react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

type Option = { value: string; label: string };

type OrganizationTotal = {
    organization_id: string;
    name: string;
    students: number;
    invoices: number;
    total: number;
};

type ClassArrears = {
    group: string;
    label: string;
    students: number;
    invoices: number;
    total: number;
};

type StudentArrears = {
    enrollment_id: string;
    name: string;
    class: string | null;
    student_number: string | null;
    nisn: string | null;
    active: boolean;
    invoices: number;
    total: number;
    oldest_due: string;
};

type Props = {
    organizations: Option[];
    organizationId: string | null;
    today: string;
    summary: OrganizationTotal[];
    classOptions: Option[];
    classFilter: string | null;
    arrears: {
        total: number;
        student_count: number;
        invoice_count: number;
        classes: ClassArrears[];
        students: StudentArrears[];
        page: number;
        last_page: number;
    } | null;
};

const rupiah = new Intl.NumberFormat('id-ID');
const formatRupiah = (amount: number) => `Rp ${rupiah.format(amount)}`;

const dateFormat = new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium' });

function formatDate(value: string): string {
    const [year, month, day] = value.split('-').map(Number);

    return dateFormat.format(new Date(year, month - 1, day));
}

const selectClass =
    'h-9 w-full min-w-0 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';

/**
 * Tunggakan siswa (PRD-003 §6, FN-4a §17): sisa tagihan Belum lunas yang sudah lewat jatuh tempo.
 * Ringkasan per lembaga (bila cakupan lebih dari satu lembaga), lalu per kelas & per siswa; unduh .xlsx.
 */
export default function Arrears({
    organizations,
    organizationId,
    today,
    summary,
    classOptions,
    classFilter,
    arrears,
}: Props) {
    const params = (overrides: Record<string, string | null>) => {
        const merged: Record<string, string | null> = {
            lembaga: organizationId,
            kelas: classFilter,
            ...overrides,
        };
        const clean: Record<string, string> = {};

        for (const [key, value] of Object.entries(merged)) {
            if (value) {
                clean[key] = value;
            }
        }

        return clean;
    };

    const visit = (overrides: Record<string, string | null>) =>
        router.get('/keuangan/tunggakan', params(overrides), {
            preserveScroll: true,
        });

    const exportUrl = `/keuangan/tunggakan/unduh?${new URLSearchParams(params({}))}`;

    return (
        <>
            <Head title="Tunggakan" />

            <div className="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-4 p-4">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <Heading
                        title="Tunggakan"
                        description={`Sisa tagihan yang belum lunas dan sudah lewat jatuh tempo per ${formatDate(today)}, dari semua tahun ajaran (termasuk siswa yang sudah keluar).`}
                    />
                    {arrears !== null && arrears.student_count > 0 && (
                        <div className="grid grid-cols-[repeat(auto-fit,minmax(10rem,1fr))] gap-2 sm:flex">
                            <Button variant="outline" asChild>
                                <a href={exportUrl}>
                                    <Download />
                                    Unduh .xlsx
                                </a>
                            </Button>
                        </div>
                    )}
                </div>

                {organizations.length > 1 && (
                    <section className="space-y-2">
                        <h2 className="text-sm font-medium">
                            Ringkasan per lembaga
                        </h2>
                        {summary.length === 0 ? (
                            <Empty>
                                Tidak ada tunggakan di lembaga mana pun.
                            </Empty>
                        ) : (
                            <ul className="divide-y rounded-xl border">
                                {summary.map((row) => (
                                    <li key={row.organization_id}>
                                        <button
                                            type="button"
                                            onClick={() =>
                                                visit({
                                                    lembaga:
                                                        row.organization_id,
                                                    kelas: null,
                                                    halaman: null,
                                                })
                                            }
                                            className={`flex w-full flex-wrap items-center justify-between gap-2 p-3 text-left text-sm hover:bg-muted/50 ${row.organization_id === organizationId ? 'bg-muted/50 font-medium' : ''}`}
                                        >
                                            <span className="min-w-0">
                                                {row.name}
                                            </span>
                                            <span className="text-muted-foreground tabular-nums">
                                                {row.students} siswa ·{' '}
                                                <span className="font-semibold text-foreground">
                                                    {formatRupiah(row.total)}
                                                </span>
                                            </span>
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                )}

                <div className="grid gap-3 sm:grid-cols-2">
                    <SelectField
                        id="arr-organization"
                        label="Lembaga"
                        value={organizationId ?? ''}
                        options={organizations}
                        onChange={(value) =>
                            visit({
                                lembaga: value,
                                kelas: null,
                                halaman: null,
                            })
                        }
                    />
                    <SelectField
                        id="arr-class"
                        label="Kelas"
                        value={classFilter ?? ''}
                        options={[
                            { value: '', label: 'Semua kelas' },
                            ...classOptions,
                        ]}
                        onChange={(value) =>
                            visit({ kelas: value || null, halaman: null })
                        }
                    />
                </div>

                {arrears === null || arrears.student_count === 0 ? (
                    <Empty>
                        Tidak ada tunggakan di lembaga ini
                        {classFilter ? ' untuk kelas tersebut' : ''}.
                    </Empty>
                ) : (
                    <>
                        <dl className="grid grid-cols-[repeat(auto-fit,minmax(10rem,1fr))] gap-3">
                            <Stat
                                label="Siswa menunggak"
                                value={String(arrears.student_count)}
                            />
                            <Stat
                                label="Tagihan lewat jatuh tempo"
                                value={String(arrears.invoice_count)}
                            />
                            <Stat
                                label="Total tunggakan"
                                value={formatRupiah(arrears.total)}
                            />
                        </dl>

                        {classFilter === null && arrears.classes.length > 1 && (
                            <section className="space-y-2">
                                <h2 className="text-sm font-medium">
                                    Per kelas
                                </h2>
                                <div className="grid grid-cols-[repeat(auto-fill,minmax(11rem,1fr))] gap-2">
                                    {arrears.classes.map((row) => (
                                        <button
                                            key={row.group}
                                            type="button"
                                            onClick={() =>
                                                visit({
                                                    kelas: row.group,
                                                    halaman: null,
                                                })
                                            }
                                            className="rounded-xl border p-3 text-left text-sm hover:bg-muted/50"
                                        >
                                            <span className="block font-medium">
                                                {row.label}
                                            </span>
                                            <span className="block text-muted-foreground">
                                                {row.students} siswa
                                            </span>
                                            <span className="block font-semibold tabular-nums">
                                                {formatRupiah(row.total)}
                                            </span>
                                        </button>
                                    ))}
                                </div>
                            </section>
                        )}

                        <section className="space-y-2">
                            <h2 className="text-sm font-medium">Per siswa</h2>
                            <ul className="divide-y rounded-xl border">
                                {arrears.students.map((student) => (
                                    <li
                                        key={student.enrollment_id}
                                        className="flex flex-wrap items-center justify-between gap-3 p-4"
                                    >
                                        <div className="min-w-0 space-y-0.5">
                                            <p className="font-medium">
                                                {student.name}
                                                {!student.active && (
                                                    <Badge
                                                        variant="outline"
                                                        className="ml-2"
                                                    >
                                                        Sudah keluar
                                                    </Badge>
                                                )}
                                            </p>
                                            <p className="text-sm text-muted-foreground">
                                                {[
                                                    student.active
                                                        ? (student.class ??
                                                          'Tanpa kelas')
                                                        : null,
                                                    student.student_number &&
                                                        `NIS ${student.student_number}`,
                                                    `${student.invoices} tagihan`,
                                                    `terlama ${formatDate(student.oldest_due)}`,
                                                ]
                                                    .filter(Boolean)
                                                    .join(' · ')}
                                            </p>
                                        </div>
                                        <div className="flex flex-wrap items-center gap-3">
                                            <span className="font-semibold tabular-nums">
                                                {formatRupiah(student.total)}
                                            </span>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                asChild
                                            >
                                                <Link
                                                    href={`/keuangan/kasir?${new URLSearchParams({ lembaga: organizationId ?? '', siswa: student.enrollment_id })}`}
                                                >
                                                    Buka di kasir
                                                </Link>
                                            </Button>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        </section>

                        {arrears.last_page > 1 && (
                            <nav
                                aria-label="Halaman"
                                className="flex flex-wrap items-center justify-between gap-2 text-sm"
                            >
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={arrears.page <= 1}
                                    onClick={() =>
                                        visit({
                                            halaman: String(arrears.page - 1),
                                        })
                                    }
                                >
                                    Sebelumnya
                                </Button>
                                <span className="text-muted-foreground">
                                    Halaman {arrears.page} dari{' '}
                                    {arrears.last_page}
                                </span>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={arrears.page >= arrears.last_page}
                                    onClick={() =>
                                        visit({
                                            halaman: String(arrears.page + 1),
                                        })
                                    }
                                >
                                    Berikutnya
                                </Button>
                            </nav>
                        )}
                    </>
                )}
            </div>
        </>
    );
}

function SelectField({
    id,
    label,
    value,
    options,
    onChange,
}: {
    id: string;
    label: string;
    value: string;
    options: Option[];
    onChange: (value: string) => void;
}) {
    return (
        <div className="grid min-w-0 gap-1.5">
            <Label htmlFor={id}>{label}</Label>
            <select
                id={id}
                className={selectClass}
                value={value}
                onChange={(event) => onChange(event.target.value)}
            >
                {options.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
        </div>
    );
}

function Stat({ label, value }: { label: string; value: string }) {
    return (
        <div className="rounded-xl border p-3">
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="text-lg font-semibold tabular-nums">{value}</dd>
        </div>
    );
}

function Empty({ children }: { children: React.ReactNode }) {
    return (
        <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
            {children}
        </p>
    );
}
