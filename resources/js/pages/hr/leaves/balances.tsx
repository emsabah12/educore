import { Form, Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, ChevronLeft, ChevronRight, Download } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import { FormDialog } from '@/components/form-dialog';
import Heading from '@/components/heading';
import { formatLeaveDateTime } from '@/components/hr/leave-format';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Option = { value: string; label: string };

type Balance = {
    type_id: string;
    name: string;
    quota: number;
    adjustment: number;
    used: number;
    pending: number;
    remaining: number;
};

type Adjustment = {
    id: string;
    type: string;
    days: number;
    reason: string;
    by: string | null;
    created_at: string;
};

type EmployeeRow = {
    id: string;
    name: string;
    employee_number: string | null;
    organization: string | null;
    balances: Balance[];
    adjustments: Adjustment[];
};

type Filters = {
    tahun: number;
    lembaga: string;
    jenis: string;
    pegawai: string;
    cari: string;
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
    options: { organizations: Option[]; types: Option[]; years: Option[] };
    canAdjust: boolean;
    limits: { reason: number; days: number };
};

const BASE_URL = '/pegawai/cuti/saldo';

const MONTHS = [
    'Januari',
    'Februari',
    'Maret',
    'April',
    'Mei',
    'Juni',
    'Juli',
    'Agustus',
    'September',
    'Oktober',
    'November',
    'Desember',
];

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';

/**
 * Saldo cuti per pegawai (PRD-001 §13.5, HR-3d-b): jatah, penyesuaian, terpakai, menunggu, sisa;
 * penyesuaian jatah; unduh rekap .xlsx (daftar cuti + saldo).
 */
export default function LeaveBalances(props: Props) {
    const { employees, filters, options } = props;
    const [recapOpen, setRecapOpen] = useState(false);

    const applyFilters = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        const data = new FormData(event.currentTarget);
        const query: Record<string, string> = {};

        for (const [key, value] of data.entries()) {
            if (typeof value === 'string' && value.trim() !== '') {
                query[key] = value.trim();
            }
        }

        router.get(BASE_URL, query, { preserveState: true, replace: true });
    };

    const isFiltered =
        filters.lembaga !== '' ||
        filters.jenis !== '' ||
        filters.pegawai !== '' ||
        filters.cari !== '';

    return (
        <>
            <Head title="Saldo cuti" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <Button variant="ghost" size="sm" asChild>
                        <Link href="/pegawai/cuti">
                            <ArrowLeft />
                            Kelola cuti
                        </Link>
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => setRecapOpen(true)}
                    >
                        <Download />
                        Unduh rekap .xlsx
                    </Button>
                </div>

                <Heading
                    title={`Saldo cuti ${filters.tahun}`}
                    description="Jatah + penyesuaian − cuti disetujui = sisa. Pengajuan yang masih menunggu belum mengurangi sisa. Sisa hangus di akhir tahun."
                />

                {/* Grid otomatis: kolom saringan turun ke baris berikutnya di layar sempit. */}
                <form
                    key={JSON.stringify(filters)}
                    onSubmit={applyFilters}
                    className="grid grid-cols-[repeat(auto-fit,minmax(11rem,1fr))] items-end gap-3 rounded-xl border p-4"
                >
                    {filters.pegawai !== '' && (
                        <input
                            type="hidden"
                            name="pegawai"
                            value={filters.pegawai}
                        />
                    )}
                    <SelectField
                        id="tahun"
                        label="Tahun"
                        options={options.years}
                        value={String(filters.tahun)}
                    />
                    <SelectField
                        id="lembaga"
                        label="Lembaga / unit"
                        allLabel="Semua lembaga"
                        options={options.organizations}
                        value={filters.lembaga}
                    />
                    <SelectField
                        id="jenis"
                        label="Jenis"
                        allLabel="Semua jenis berjatah"
                        options={options.types}
                        value={filters.jenis}
                    />
                    <div className="grid gap-1.5">
                        <Label htmlFor="cari">Cari</Label>
                        <Input
                            id="cari"
                            name="cari"
                            defaultValue={filters.cari}
                            placeholder="Nama atau nomor induk"
                        />
                    </div>
                    <div className="grid grid-cols-[repeat(auto-fit,minmax(6rem,1fr))] gap-2">
                        <Button type="submit">Terapkan</Button>
                        {isFiltered && (
                            <Button variant="outline" asChild>
                                <Link
                                    href={`${BASE_URL}?tahun=${filters.tahun}`}
                                >
                                    Hapus
                                </Link>
                            </Button>
                        )}
                    </div>
                </form>

                <FormDialog
                    open={recapOpen}
                    onOpenChange={setRecapOpen}
                    title={`Unduh rekap cuti ${filters.tahun}`}
                >
                    <RecapForm filters={filters} />
                </FormDialog>

                {employees.data.length === 0 ? (
                    <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                        {isFiltered
                            ? 'Tidak ada pegawai aktif yang cocok dengan saringan.'
                            : 'Belum ada pegawai aktif.'}
                    </p>
                ) : (
                    <ul className="space-y-3">
                        {employees.data.map((employee) => (
                            <EmployeeCard
                                key={employee.id}
                                employee={employee}
                                year={filters.tahun}
                                canAdjust={props.canAdjust}
                                limits={props.limits}
                            />
                        ))}
                    </ul>
                )}

                <Pagination employees={employees} />
            </div>
        </>
    );
}

function RecapForm({ filters }: { filters: Filters }) {
    const [month, setMonth] = useState('');
    const query = new URLSearchParams({ tahun: String(filters.tahun) });

    if (month !== '') {
        query.set('bulan', month);
    }

    if (filters.lembaga !== '') {
        query.set('lembaga', filters.lembaga);
    }

    if (filters.jenis !== '') {
        query.set('jenis', filters.jenis);
    }

    return (
        <div className="grid gap-3">
            <div className="grid gap-1.5">
                <Label htmlFor="recap-month">Periode</Label>
                <select
                    id="recap-month"
                    className={selectClass}
                    value={month}
                    onChange={(event) => setMonth(event.target.value)}
                >
                    <option value="">Setahun penuh</option>
                    {MONTHS.map((label, index) => (
                        <option key={label} value={String(index + 1)}>
                            {label}
                        </option>
                    ))}
                </select>
            </div>
            <Button variant="outline" asChild>
                {/* Unduhan file: link biasa, bukan navigasi Inertia. */}
                <a href={`/pegawai/cuti/rekap?${query.toString()}`}>
                    <Download />
                    Unduh rekap
                </a>
            </Button>
            <p className="text-xs text-muted-foreground">
                Lembar "Daftar cuti" berisi cuti yang disetujui pada periode
                itu; lembar "Saldo" berisi saldo tiap pegawai aktif. Saringan
                lembaga & jenis ikut dipakai.
            </p>
        </div>
    );
}

function EmployeeCard({
    employee,
    year,
    canAdjust,
    limits,
}: {
    employee: EmployeeRow;
    year: number;
    canAdjust: boolean;
    limits: Props['limits'];
}) {
    const [open, setOpen] = useState(false);

    return (
        <li className="space-y-3 rounded-xl border p-4 text-sm">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0">
                    <Link
                        href={`/pegawai/${employee.id}`}
                        className="font-medium break-words underline-offset-4 hover:underline"
                    >
                        {employee.name}
                    </Link>
                    <p className="text-muted-foreground">
                        {[
                            employee.employee_number,
                            employee.organization ?? 'Tanpa penempatan utama',
                        ]
                            .filter(Boolean)
                            .join(' · ')}
                    </p>
                </div>
                {canAdjust && employee.balances.length > 0 && (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => setOpen((value) => !value)}
                    >
                        {open ? 'Tutup' : 'Sesuaikan jatah'}
                    </Button>
                )}
            </div>

            {employee.balances.length === 0 ? (
                <p className="text-muted-foreground">
                    Belum ada jenis cuti berjatah.
                </p>
            ) : (
                <div className="overflow-x-auto">
                    <table className="w-full min-w-[28rem] text-left">
                        <thead className="text-xs text-muted-foreground">
                            <tr>
                                <th className="py-1 pr-2 font-normal">Jenis</th>
                                <th className="py-1 pr-2 text-right font-normal">
                                    Jatah
                                </th>
                                <th className="py-1 pr-2 text-right font-normal">
                                    Penyesuaian
                                </th>
                                <th className="py-1 pr-2 text-right font-normal">
                                    Terpakai
                                </th>
                                <th className="py-1 pr-2 text-right font-normal">
                                    Menunggu
                                </th>
                                <th className="py-1 text-right font-normal">
                                    Sisa
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {employee.balances.map((balance) => (
                                <tr key={balance.type_id} className="border-t">
                                    <td className="py-1.5 pr-2">
                                        {balance.name}
                                    </td>
                                    <td className="py-1.5 pr-2 text-right">
                                        {balance.quota}
                                    </td>
                                    <td className="py-1.5 pr-2 text-right">
                                        {balance.adjustment > 0
                                            ? `+${balance.adjustment}`
                                            : balance.adjustment}
                                    </td>
                                    <td className="py-1.5 pr-2 text-right">
                                        {balance.used}
                                    </td>
                                    <td className="py-1.5 pr-2 text-right">
                                        {balance.pending}
                                    </td>
                                    <td className="py-1.5 text-right font-semibold">
                                        {balance.remaining}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {open && (
                <AdjustForm
                    employee={employee}
                    year={year}
                    limits={limits}
                    onDone={() => setOpen(false)}
                />
            )}

            {employee.adjustments.length > 0 && (
                <details>
                    <summary className="cursor-pointer text-muted-foreground">
                        Riwayat penyesuaian {year} (
                        {employee.adjustments.length})
                    </summary>
                    <ul className="mt-2 divide-y rounded-md border">
                        {employee.adjustments.map((item) => (
                            <li key={item.id} className="space-y-0.5 p-3">
                                <p>
                                    <span className="font-medium">
                                        {item.days > 0
                                            ? `+${item.days}`
                                            : item.days}{' '}
                                        hari
                                    </span>{' '}
                                    · {item.type}
                                </p>
                                <p className="break-words whitespace-pre-line">
                                    {item.reason}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {item.by ? `${item.by} · ` : ''}
                                    {formatLeaveDateTime(item.created_at)}
                                </p>
                            </li>
                        ))}
                    </ul>
                </details>
            )}
        </li>
    );
}

function AdjustForm({
    employee,
    year,
    limits,
    onDone,
}: {
    employee: EmployeeRow;
    year: number;
    limits: Props['limits'];
    onDone: () => void;
}) {
    return (
        <Form
            action={`${BASE_URL}/${employee.id}`}
            method="post"
            errorBag={`adjust-${employee.id}`}
            options={{ preserveScroll: true }}
            onSuccess={onDone}
            className="space-y-3 rounded-md border border-primary/30 bg-muted/20 p-3"
        >
            {({ processing, errors }) => (
                <>
                    <input type="hidden" name="year" value={year} />
                    <InputError message={errors.form ?? errors.year} />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="grid content-start gap-1.5">
                            <Label htmlFor={`adj-type-${employee.id}`}>
                                Jenis
                            </Label>
                            <select
                                id={`adj-type-${employee.id}`}
                                name="leave_type_id"
                                className={selectClass}
                                defaultValue={
                                    employee.balances[0]?.type_id ?? ''
                                }
                                required
                            >
                                {employee.balances.map((balance) => (
                                    <option
                                        key={balance.type_id}
                                        value={balance.type_id}
                                    >
                                        {balance.name} (sisa {balance.remaining}
                                        )
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.leave_type_id} />
                        </div>
                        <div className="grid content-start gap-1.5">
                            <Label htmlFor={`adj-days-${employee.id}`}>
                                Jumlah hari (minus untuk mengurangi)
                            </Label>
                            <Input
                                id={`adj-days-${employee.id}`}
                                name="days"
                                type="number"
                                min={-limits.days}
                                max={limits.days}
                                step={1}
                                required
                            />
                            <InputError message={errors.days} />
                        </div>
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor={`adj-reason-${employee.id}`}>
                            Alasan
                        </Label>
                        <textarea
                            id={`adj-reason-${employee.id}`}
                            name="reason"
                            rows={2}
                            maxLength={limits.reason}
                            required
                            className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                        />
                        <InputError message={errors.reason} />
                    </div>
                    <Button
                        type="submit"
                        disabled={processing}
                        className="w-full sm:w-auto"
                    >
                        {processing
                            ? 'Menyimpan…'
                            : `Simpan penyesuaian ${year}`}
                    </Button>
                </>
            )}
        </Form>
    );
}

function SelectField({
    id,
    label,
    allLabel,
    options,
    value,
}: {
    id: string;
    label: string;
    allLabel?: string;
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
                {allLabel !== undefined && <option value="">{allLabel}</option>}
                {options.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
        </div>
    );
}

function Pagination({ employees }: { employees: Props['employees'] }) {
    if (employees.total === 0) {
        return null;
    }

    return (
        <nav
            aria-label="Halaman saldo cuti"
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
    children: ReactNode;
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
