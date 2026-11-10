import { Head, Link, router } from '@inertiajs/react';
import { Download, Search } from 'lucide-react';
import type { FormEvent } from 'react';
import Heading from '@/components/heading';
import { PageTabs, usePageTab } from '@/components/page-tabs';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { selectClass } from '@/components/native-select';

type Option = { value: string; label: string };

type OrganizationSavings = {
    organization_id: string;
    name: string;
    students: number;
    total: number;
};

type ClassSavings = {
    group: string;
    label: string;
    students: number;
    total: number;
};

type StudentSavings = {
    enrollment_id: string;
    name: string;
    class: string | null;
    student_number: string | null;
    nisn: string | null;
    active: boolean;
    balance: number;
    last_date: string;
};

type SavingsTransaction = {
    id: string;
    entry_date: string;
    slip_number: string;
    name: string;
    class: string | null;
    kind: 'SETOR' | 'TARIK' | 'BAYAR_TAGIHAN';
    kind_label: string;
    method: string | null;
    amount: number;
    taken_by: string | null;
    recorded_by: string | null;
    status: 'SAH' | 'BATAL';
    void_reason: string | null;
};

type Props = {
    organizations: Option[];
    organizationId: string;
    today: string;
    summary: OrganizationSavings[];
    classOptions: Option[];
    filters: { kelas: string | null; cari: string; semua: boolean };
    balances: {
        total: number;
        student_count: number;
        classes: ClassSavings[];
        students: StudentSavings[];
        page: number;
        last_page: number;
    };
    months: Option[];
    month: string;
    monthly: {
        opening: number;
        deposits: number;
        withdrawals: number;
        payments: number;
        closing: number;
        deposit_count: number;
        withdrawal_count: number;
        payment_count: number;
        voided_count: number;
        entries: SavingsTransaction[];
    };
};

const TABS = ['saldo', 'rekap'] as const;

const rupiah = new Intl.NumberFormat('id-ID');
const formatRupiah = (amount: number) => `Rp ${rupiah.format(amount)}`;

const dateFormat = new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium' });

function formatDate(value: string): string {
    const [year, month, day] = value.split('-').map(Number);

    return dateFormat.format(new Date(year, month - 1, day));
}

/**
 * Tabungan siswa (PRD-005 §6, TB-2): tab Saldo (titipan per lembaga, saldo per siswa, unduh .xlsx) dan tab Rekap
 * bulanan (saldo awal/akhir, setoran, penarikan, bayar tagihan, daftar transaksi, unduh .xlsx). Setor/tarik di Kasir.
 */
export default function Savings({
    organizations,
    organizationId,
    today,
    summary,
    classOptions,
    filters,
    balances,
    months,
    month,
    monthly,
}: Props) {
    const [tab, setTab] = usePageTab(TABS, 'saldo');

    const params = (overrides: Record<string, string | null>) => {
        const merged: Record<string, string | null> = {
            lembaga: organizationId,
            kelas: filters.kelas,
            cari: filters.cari || null,
            semua: filters.semua ? '1' : null,
            bulan: month,
            tab: tab === 'saldo' ? null : tab,
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
        router.get('/keuangan/tabungan', params(overrides), {
            preserveScroll: true,
        });

    const search = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const value = new FormData(event.currentTarget).get('cari');
        visit({
            cari: typeof value === 'string' ? value.trim() || null : null,
            halaman: null,
        });
    };

    const balancesExport = `/keuangan/tabungan/unduh-saldo?${new URLSearchParams(params({ bulan: null, tab: null }))}`;
    const monthlyExport = `/keuangan/tabungan/unduh-rekap?${new URLSearchParams({ lembaga: organizationId, bulan: month })}`;

    return (
        <>
            <Head title="Tabungan" />

            <div className="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-4 p-4">
                <Heading
                    title="Tabungan siswa"
                    description="Saldo titipan tabungan siswa per lembaga dan rekap bulanannya. Setoran & penarikan dicatat di Kasir (tab Tabungan)."
                />

                {organizations.length > 1 && (
                    <section className="space-y-2">
                        <h2 className="text-sm font-medium">
                            Titipan per lembaga
                        </h2>
                        {summary.length === 0 ? (
                            <Empty>
                                Belum ada saldo tabungan di lembaga mana pun.
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
                                                    cari: null,
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

                <div className="grid gap-3 sm:max-w-sm">
                    <SelectField
                        id="sav-organization"
                        label="Lembaga"
                        value={organizationId}
                        options={organizations}
                        onChange={(value) =>
                            visit({
                                lembaga: value,
                                kelas: null,
                                cari: null,
                                halaman: null,
                            })
                        }
                    />
                </div>

                <PageTabs
                    label="Bagian tabungan"
                    tabs={[
                        { value: 'saldo', label: 'Saldo per siswa' },
                        { value: 'rekap', label: 'Rekap bulanan' },
                    ]}
                    value={tab}
                    onChange={setTab}
                />

                {tab === 'saldo' ? (
                    <section aria-label="Saldo per siswa" className="space-y-4">
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.5fr)_auto] lg:items-end">
                            <SelectField
                                id="sav-class"
                                label="Kelas"
                                value={filters.kelas ?? ''}
                                options={[
                                    { value: '', label: 'Semua kelas' },
                                    ...classOptions,
                                ]}
                                onChange={(value) =>
                                    visit({
                                        kelas: value || null,
                                        halaman: null,
                                    })
                                }
                            />
                            <form
                                key={filters.cari}
                                onSubmit={search}
                                className="grid min-w-0 gap-1.5"
                            >
                                <Label htmlFor="sav-search">Cari siswa</Label>
                                <div className="flex gap-2">
                                    <Input
                                        id="sav-search"
                                        name="cari"
                                        defaultValue={filters.cari}
                                        maxLength={100}
                                        placeholder="Nama, NIS, atau NISN"
                                    />
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        aria-label="Cari"
                                    >
                                        <Search />
                                    </Button>
                                </div>
                            </form>
                            <label className="flex h-9 items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={filters.semua}
                                    onChange={(event) =>
                                        visit({
                                            semua: event.target.checked
                                                ? '1'
                                                : null,
                                            halaman: null,
                                        })
                                    }
                                    className="size-4 accent-foreground"
                                />
                                Tampilkan juga saldo Rp 0
                            </label>
                        </div>

                        <div className="flex flex-wrap items-end justify-between gap-3">
                            <dl className="grid flex-1 grid-cols-[repeat(auto-fit,minmax(10rem,1fr))] gap-3">
                                <Stat
                                    label="Siswa"
                                    value={String(balances.student_count)}
                                />
                                <Stat
                                    label={`Total saldo per ${formatDate(today)}`}
                                    value={formatRupiah(balances.total)}
                                />
                            </dl>
                            {balances.student_count > 0 && (
                                <Button variant="outline" asChild>
                                    <a href={balancesExport}>
                                        <Download />
                                        Unduh .xlsx
                                    </a>
                                </Button>
                            )}
                        </div>

                        {filters.kelas === null &&
                            !filters.cari &&
                            balances.classes.length > 1 && (
                                <div className="grid grid-cols-[repeat(auto-fill,minmax(11rem,1fr))] gap-2">
                                    {balances.classes.map((row) => (
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
                                                {row.students} siswa bersaldo
                                            </span>
                                            <span className="block font-semibold tabular-nums">
                                                {formatRupiah(row.total)}
                                            </span>
                                        </button>
                                    ))}
                                </div>
                            )}

                        {balances.student_count === 0 ? (
                            <Empty>
                                {filters.kelas || filters.cari
                                    ? 'Tidak ada siswa yang cocok dengan saringan.'
                                    : 'Belum ada siswa yang punya saldo tabungan di lembaga ini.'}
                            </Empty>
                        ) : (
                            <ul className="divide-y rounded-xl border">
                                {balances.students.map((student) => (
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
                                                        {student.balance > 0
                                                            ? 'Sudah keluar, saldo belum diambil'
                                                            : 'Sudah keluar'}
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
                                                    student.last_date &&
                                                        `terakhir ${formatDate(student.last_date)}`,
                                                ]
                                                    .filter(Boolean)
                                                    .join(' · ')}
                                            </p>
                                        </div>
                                        <div className="flex flex-wrap items-center gap-3">
                                            <span className="font-semibold tabular-nums">
                                                {formatRupiah(student.balance)}
                                            </span>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                asChild
                                            >
                                                <Link
                                                    href={`/keuangan/kasir?${new URLSearchParams({ lembaga: organizationId, siswa: student.enrollment_id, tab: 'tabungan' })}`}
                                                >
                                                    Buka di kasir
                                                </Link>
                                            </Button>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}

                        {balances.last_page > 1 && (
                            <nav
                                aria-label="Halaman"
                                className="flex flex-wrap items-center justify-between gap-2 text-sm"
                            >
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={balances.page <= 1}
                                    onClick={() =>
                                        visit({
                                            halaman: String(balances.page - 1),
                                        })
                                    }
                                >
                                    Sebelumnya
                                </Button>
                                <span className="text-muted-foreground">
                                    Halaman {balances.page} dari{' '}
                                    {balances.last_page}
                                </span>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={
                                        balances.page >= balances.last_page
                                    }
                                    onClick={() =>
                                        visit({
                                            halaman: String(balances.page + 1),
                                        })
                                    }
                                >
                                    Berikutnya
                                </Button>
                            </nav>
                        )}
                    </section>
                ) : (
                    <section aria-label="Rekap bulanan" className="space-y-4">
                        <div className="flex flex-wrap items-end justify-between gap-3">
                            <div className="w-full sm:w-64">
                                <SelectField
                                    id="sav-month"
                                    label="Bulan"
                                    value={month}
                                    options={months}
                                    onChange={(value) =>
                                        visit({ bulan: value })
                                    }
                                />
                            </div>
                            <Button variant="outline" asChild>
                                <a href={monthlyExport}>
                                    <Download />
                                    Unduh .xlsx
                                </a>
                            </Button>
                        </div>

                        <dl className="grid grid-cols-[repeat(auto-fit,minmax(9rem,1fr))] gap-3">
                            <Stat
                                label="Saldo awal bulan"
                                value={formatRupiah(monthly.opening)}
                            />
                            <Stat
                                label={`Setoran (${monthly.deposit_count})`}
                                value={formatRupiah(monthly.deposits)}
                            />
                            <Stat
                                label={`Penarikan (${monthly.withdrawal_count})`}
                                value={formatRupiah(monthly.withdrawals)}
                            />
                            {monthly.payment_count > 0 && (
                                <Stat
                                    label={`Bayar tagihan (${monthly.payment_count})`}
                                    value={formatRupiah(monthly.payments)}
                                />
                            )}
                            <Stat
                                label="Saldo akhir bulan"
                                value={formatRupiah(monthly.closing)}
                            />
                        </dl>
                        {monthly.voided_count > 0 && (
                            <p className="text-xs text-muted-foreground">
                                {monthly.voided_count} transaksi dibatalkan
                                tidak dihitung (tetap tampil di daftar dengan
                                tanda Batal).
                            </p>
                        )}

                        {monthly.entries.length === 0 ? (
                            <Empty>
                                Tidak ada transaksi tabungan bulan ini.
                            </Empty>
                        ) : (
                            <ul className="divide-y rounded-xl border">
                                {monthly.entries.map((entry) => {
                                    const voided = entry.status === 'BATAL';

                                    return (
                                        <li
                                            key={entry.id}
                                            className="flex flex-wrap items-start justify-between gap-3 p-4 text-sm"
                                        >
                                            <div className="min-w-0 space-y-0.5">
                                                <p className="font-medium">
                                                    {entry.name}
                                                    {entry.class && (
                                                        <span className="font-normal text-muted-foreground">
                                                            {' '}
                                                            · {entry.class}
                                                        </span>
                                                    )}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    {[
                                                        formatDate(
                                                            entry.entry_date,
                                                        ),
                                                        entry.kind_label,
                                                        entry.method,
                                                        entry.taken_by &&
                                                            `diambil ${entry.taken_by}`,
                                                        entry.recorded_by &&
                                                            `petugas ${entry.recorded_by}`,
                                                    ]
                                                        .filter(Boolean)
                                                        .join(' · ')}
                                                </p>
                                                {entry.void_reason && (
                                                    <p className="text-xs text-muted-foreground">
                                                        Dibatalkan:{' '}
                                                        {entry.void_reason}
                                                    </p>
                                                )}
                                            </div>
                                            <div className="flex flex-col items-end gap-1">
                                                <span
                                                    className={`font-medium tabular-nums ${voided ? 'text-muted-foreground line-through' : ''}`}
                                                >
                                                    {entry.kind === 'SETOR'
                                                        ? '+'
                                                        : '−'}
                                                    {formatRupiah(entry.amount)}
                                                </span>
                                                <Link
                                                    href={`/keuangan/slip-tabungan/${entry.id}`}
                                                    className="font-mono text-xs text-muted-foreground underline underline-offset-4"
                                                >
                                                    {entry.slip_number}
                                                </Link>
                                                {voided && (
                                                    <Badge variant="outline">
                                                        Batal
                                                    </Badge>
                                                )}
                                            </div>
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                    </section>
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
