import { Head, Link, router } from '@inertiajs/react';
import { Download } from 'lucide-react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

type Option = { value: string; label: string };

type Totals = {
    count: number;
    amount: number;
    cash: number;
    transfer: number;
    voided_count: number;
    voided_amount: number;
};

type DayRow = {
    date: string;
    count: number;
    cash: number;
    transfer: number;
    total: number;
};

type Transaction = {
    id: string;
    organization_id: string;
    organization: string;
    receipt_number: string;
    paid_on: string;
    method: string;
    amount: number;
    status: 'SAH' | 'BATAL';
    void_reason: string | null;
    name: string;
    class: string | null;
    items: string[];
    recorded_by: string | null;
};

type Props = {
    organizations: Option[];
    organizationId: string;
    months: Option[];
    month: string;
    day: string | null;
    summary: {
        totals: Totals;
        days: DayRow[];
        fee_types: { name: string; amount: number }[];
        organizations: {
            organization_id: string;
            name: string;
            count: number;
            total: number;
        }[];
    };
    transactions: {
        rows: Transaction[];
        total: number;
        page: number;
        last_page: number;
    };
};

const ALL = 'semua';

const rupiah = new Intl.NumberFormat('id-ID');
const formatRupiah = (amount: number) => `Rp ${rupiah.format(amount)}`;

const dateFormat = new Intl.DateTimeFormat('id-ID', {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
});
const longDate = new Intl.DateTimeFormat('id-ID', { dateStyle: 'long' });

function toDate(value: string): Date {
    const [year, month, day] = value.split('-').map(Number);

    return new Date(year, month - 1, day);
}

const selectClass =
    'h-9 w-full min-w-0 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';

/**
 * Rekap penerimaan (PRD-003 §6, FN-4b §18): satu bulan menurut tanggal bayar; per hari (klik untuk melihat
 * transaksi hari itu), per jenis biaya, per lembaga; daftar transaksi; unduh .xlsx.
 */
export default function ReceiptRecap({
    organizations,
    organizationId,
    months,
    month,
    day,
    summary,
    transactions,
}: Props) {
    const allOrganizations = organizationId === ALL;
    const monthLabel =
        months.find((option) => option.value === month)?.label ?? month;
    const { totals } = summary;

    const visit = (overrides: Record<string, string | null>) => {
        const merged: Record<string, string | null> = {
            lembaga: organizationId,
            bulan: month,
            tanggal: day,
            ...overrides,
        };
        const clean: Record<string, string> = {};

        for (const [key, value] of Object.entries(merged)) {
            if (value) {
                clean[key] = value;
            }
        }

        router.get('/keuangan/rekap-penerimaan', clean, {
            preserveScroll: true,
        });
    };

    const exportUrl = `/keuangan/rekap-penerimaan/unduh?${new URLSearchParams({ lembaga: organizationId, bulan: month })}`;

    return (
        <>
            <Head title="Rekap penerimaan" />

            <div className="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-4 p-4">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <Heading
                        title="Rekap penerimaan"
                        description="Uang yang diterima menurut tanggal bayar di kuitansi. Pembayaran yang dibatalkan tidak dihitung."
                    />
                    {(totals.count > 0 || totals.voided_count > 0) && (
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

                <div className="grid gap-3 sm:grid-cols-2">
                    <SelectField
                        id="rcp-organization"
                        label="Lembaga"
                        value={organizationId}
                        options={organizations}
                        onChange={(value) =>
                            visit({
                                lembaga: value,
                                tanggal: null,
                                halaman: null,
                            })
                        }
                    />
                    <SelectField
                        id="rcp-month"
                        label="Bulan"
                        value={month}
                        options={months}
                        onChange={(value) =>
                            visit({
                                bulan: value,
                                tanggal: null,
                                halaman: null,
                            })
                        }
                    />
                </div>

                <dl className="grid grid-cols-[repeat(auto-fit,minmax(10rem,1fr))] gap-3">
                    <Stat
                        label={`Diterima ${monthLabel}`}
                        value={formatRupiah(totals.amount)}
                    />
                    <Stat label="Tunai" value={formatRupiah(totals.cash)} />
                    <Stat
                        label="Transfer bank"
                        value={formatRupiah(totals.transfer)}
                    />
                    <Stat label="Transaksi" value={String(totals.count)} />
                </dl>

                {totals.voided_count > 0 && (
                    <p className="text-sm text-muted-foreground">
                        {totals.voided_count} pembayaran dibatalkan (
                        {formatRupiah(totals.voided_amount)}) tidak dihitung.
                    </p>
                )}

                {totals.count === 0 && totals.voided_count === 0 ? (
                    <Empty>Belum ada pembayaran pada {monthLabel}.</Empty>
                ) : (
                    <>
                        <div className="grid gap-4 lg:grid-cols-2">
                            <section className="min-w-0 space-y-2">
                                <h2 className="text-sm font-medium">
                                    Per hari
                                </h2>
                                {summary.days.length === 0 ? (
                                    <Empty>Tidak ada pembayaran sah.</Empty>
                                ) : (
                                    <div className="overflow-x-auto rounded-xl border">
                                        <table className="w-full text-sm">
                                            <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                                                <tr>
                                                    <th className="p-2 font-medium">
                                                        Tanggal
                                                    </th>
                                                    <th className="p-2 text-right font-medium">
                                                        Tunai
                                                    </th>
                                                    <th className="p-2 text-right font-medium">
                                                        Transfer
                                                    </th>
                                                    <th className="p-2 text-right font-medium">
                                                        Total
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y">
                                                {summary.days.map((row) => (
                                                    <tr
                                                        key={row.date}
                                                        className={
                                                            row.date === day
                                                                ? 'bg-muted/50'
                                                                : undefined
                                                        }
                                                    >
                                                        <td className="p-2">
                                                            <button
                                                                type="button"
                                                                className="text-left font-medium underline-offset-4 hover:underline"
                                                                onClick={() =>
                                                                    visit({
                                                                        tanggal:
                                                                            row.date ===
                                                                            day
                                                                                ? null
                                                                                : row.date,
                                                                        halaman:
                                                                            null,
                                                                    })
                                                                }
                                                            >
                                                                {dateFormat.format(
                                                                    toDate(
                                                                        row.date,
                                                                    ),
                                                                )}
                                                            </button>
                                                            <span className="block text-xs text-muted-foreground">
                                                                {row.count}{' '}
                                                                transaksi
                                                            </span>
                                                        </td>
                                                        <td className="p-2 text-right tabular-nums">
                                                            {rupiah.format(
                                                                row.cash,
                                                            )}
                                                        </td>
                                                        <td className="p-2 text-right tabular-nums">
                                                            {rupiah.format(
                                                                row.transfer,
                                                            )}
                                                        </td>
                                                        <td className="p-2 text-right font-semibold tabular-nums">
                                                            {rupiah.format(
                                                                row.total,
                                                            )}
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </section>

                            <div className="min-w-0 space-y-4">
                                <section className="space-y-2">
                                    <h2 className="text-sm font-medium">
                                        Per jenis biaya
                                    </h2>
                                    {summary.fee_types.length === 0 ? (
                                        <Empty>Tidak ada pembayaran sah.</Empty>
                                    ) : (
                                        <ul className="divide-y rounded-xl border text-sm">
                                            {summary.fee_types.map((row) => (
                                                <li
                                                    key={row.name}
                                                    className="flex items-center justify-between gap-2 p-2"
                                                >
                                                    <span>{row.name}</span>
                                                    <span className="font-semibold tabular-nums">
                                                        {formatRupiah(
                                                            row.amount,
                                                        )}
                                                    </span>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </section>

                                {summary.organizations.length > 0 && (
                                    <section className="space-y-2">
                                        <h2 className="text-sm font-medium">
                                            Per lembaga
                                        </h2>
                                        <ul className="divide-y rounded-xl border text-sm">
                                            {summary.organizations.map(
                                                (row) => (
                                                    <li
                                                        key={
                                                            row.organization_id
                                                        }
                                                    >
                                                        <button
                                                            type="button"
                                                            className="flex w-full flex-wrap items-center justify-between gap-2 p-2 text-left hover:bg-muted/50"
                                                            onClick={() =>
                                                                visit({
                                                                    lembaga:
                                                                        row.organization_id,
                                                                    tanggal:
                                                                        null,
                                                                    halaman:
                                                                        null,
                                                                })
                                                            }
                                                        >
                                                            <span>
                                                                {row.name}
                                                            </span>
                                                            <span className="text-muted-foreground tabular-nums">
                                                                {row.count}{' '}
                                                                transaksi ·{' '}
                                                                <span className="font-semibold text-foreground">
                                                                    {formatRupiah(
                                                                        row.total,
                                                                    )}
                                                                </span>
                                                            </span>
                                                        </button>
                                                    </li>
                                                ),
                                            )}
                                        </ul>
                                    </section>
                                )}
                            </div>
                        </div>

                        <section className="space-y-2">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <h2 className="text-sm font-medium">
                                    Transaksi{' '}
                                    {day
                                        ? longDate.format(toDate(day))
                                        : monthLabel}{' '}
                                    ({transactions.total})
                                </h2>
                                {day && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            visit({
                                                tanggal: null,
                                                halaman: null,
                                            })
                                        }
                                    >
                                        Semua tanggal
                                    </Button>
                                )}
                            </div>

                            {transactions.rows.length === 0 ? (
                                <Empty>Tidak ada transaksi.</Empty>
                            ) : (
                                <ul className="divide-y rounded-xl border">
                                    {transactions.rows.map((row) => (
                                        <li
                                            key={row.id}
                                            className="flex flex-wrap items-start justify-between gap-2 p-3 text-sm"
                                        >
                                            <div className="min-w-0 space-y-0.5">
                                                <p className="font-medium">
                                                    {row.name}
                                                    {row.class &&
                                                        ` (${row.class})`}
                                                    {row.status === 'BATAL' && (
                                                        <Badge
                                                            variant="outline"
                                                            className="ml-2"
                                                        >
                                                            Batal
                                                        </Badge>
                                                    )}
                                                </p>
                                                <p className="text-muted-foreground">
                                                    {[
                                                        dateFormat.format(
                                                            toDate(row.paid_on),
                                                        ),
                                                        allOrganizations
                                                            ? row.organization
                                                            : null,
                                                        row.method,
                                                        row.recorded_by &&
                                                            `oleh ${row.recorded_by}`,
                                                    ]
                                                        .filter(Boolean)
                                                        .join(' · ')}
                                                </p>
                                                {row.items.length > 0 && (
                                                    <p className="text-muted-foreground">
                                                        {row.items.join(', ')}
                                                    </p>
                                                )}
                                                {row.void_reason && (
                                                    <p className="text-destructive">
                                                        Dibatalkan:{' '}
                                                        {row.void_reason}
                                                    </p>
                                                )}
                                            </div>
                                            <div className="text-right">
                                                <p
                                                    className={`font-semibold tabular-nums ${row.status === 'BATAL' ? 'text-muted-foreground line-through' : ''}`}
                                                >
                                                    {formatRupiah(row.amount)}
                                                </p>
                                                <Link
                                                    href={`/keuangan/kuitansi/${row.id}`}
                                                    className="font-mono text-xs underline underline-offset-4"
                                                >
                                                    {row.receipt_number}
                                                </Link>
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            )}

                            {transactions.last_page > 1 && (
                                <nav
                                    aria-label="Halaman"
                                    className="flex flex-wrap items-center justify-between gap-2 text-sm"
                                >
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        disabled={transactions.page <= 1}
                                        onClick={() =>
                                            visit({
                                                halaman: String(
                                                    transactions.page - 1,
                                                ),
                                            })
                                        }
                                    >
                                        Sebelumnya
                                    </Button>
                                    <span className="text-muted-foreground">
                                        Halaman {transactions.page} dari{' '}
                                        {transactions.last_page}
                                    </span>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        disabled={
                                            transactions.page >=
                                            transactions.last_page
                                        }
                                        onClick={() =>
                                            visit({
                                                halaman: String(
                                                    transactions.page + 1,
                                                ),
                                            })
                                        }
                                    >
                                        Berikutnya
                                    </Button>
                                </nav>
                            )}
                        </section>
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
