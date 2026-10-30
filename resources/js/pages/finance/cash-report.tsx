import { Head, router } from '@inertiajs/react';
import { Download } from 'lucide-react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

type Option = { value: string; label: string };

type CategoryRow = { category: string; amount: number };

type BookRow = {
    organization_id: string | null;
    name: string;
    opening: number;
    income: number;
    expense: number;
    transfer_in: number;
    transfer_out: number;
    closing: number;
};

type Props = {
    books: Option[];
    bookKey: string;
    periods: Option[];
    period: string;
    report: {
        from: string;
        to: string;
        opening: number;
        closing: number;
        income: CategoryRow[];
        income_total: number;
        expense: CategoryRow[];
        expense_total: number;
        transfer_in: number;
        transfer_out: number;
        months: { month: string; income: number; expense: number }[];
        books: BookRow[];
    };
};

const rupiah = new Intl.NumberFormat('id-ID');
const formatRupiah = (amount: number) =>
    `${amount < 0 ? '−' : ''}Rp ${rupiah.format(Math.abs(amount))}`;

const dateFormat = new Intl.DateTimeFormat('id-ID', { dateStyle: 'long' });

function formatDate(value: string): string {
    const [year, month, day] = value.split('-').map(Number);

    return dateFormat.format(new Date(year, month - 1, day));
}

const selectClass =
    'h-9 w-full min-w-0 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';

/**
 * Laporan arus kas (PRD-004 §6, KS-4): saldo awal, pemasukan & pengeluaran per kategori, mutasi, saldo akhir;
 * rincian per bulan (periode tahunan) dan per kas (bila semua kas). Unduh .xlsx.
 */
export default function CashReport({
    books,
    bookKey,
    periods,
    period,
    report,
}: Props) {
    const visit = (overrides: Record<string, string>) =>
        router.get(
            '/keuangan/laporan-kas',
            { kas: bookKey, periode: period, ...overrides },
            { preserveScroll: true },
        );

    const exportUrl = `/keuangan/laporan-kas/unduh?${new URLSearchParams({ kas: bookKey, periode: period })}`;
    const net = report.income_total - report.expense_total;

    return (
        <>
            <Head title="Laporan kas" />

            <div className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-4 p-4">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <Heading
                        title="Laporan kas"
                        description={`Arus kas ${formatDate(report.from)} – ${formatDate(report.to)}. Hanya transaksi sah; mutasi antar-rekening/kas dihitung terpisah.`}
                    />
                    <div className="grid grid-cols-[repeat(auto-fit,minmax(10rem,1fr))] gap-2 sm:flex">
                        <Button variant="outline" asChild>
                            <a href={exportUrl}>
                                <Download />
                                Unduh .xlsx
                            </a>
                        </Button>
                    </div>
                </div>

                <div className="grid gap-3 sm:grid-cols-2">
                    <SelectField
                        id="rep-book"
                        label="Kas"
                        value={bookKey}
                        options={books}
                        onChange={(value) => visit({ kas: value })}
                    />
                    <SelectField
                        id="rep-period"
                        label="Periode"
                        value={period}
                        options={periods}
                        onChange={(value) => visit({ periode: value })}
                    />
                </div>

                <dl className="grid grid-cols-[repeat(auto-fit,minmax(9rem,1fr))] gap-3">
                    <Stat
                        label="Saldo awal"
                        value={formatRupiah(report.opening)}
                    />
                    <Stat
                        label="Pemasukan"
                        value={formatRupiah(report.income_total)}
                    />
                    <Stat
                        label="Pengeluaran"
                        value={formatRupiah(report.expense_total)}
                    />
                    <Stat label="Selisih" value={formatRupiah(net)} />
                    <Stat
                        label="Saldo akhir"
                        value={formatRupiah(report.closing)}
                    />
                </dl>

                <div className="grid gap-4 md:grid-cols-2">
                    <CategoryTable
                        title="Pemasukan per kategori"
                        rows={report.income}
                        total={report.income_total}
                    />
                    <CategoryTable
                        title="Pengeluaran per kategori"
                        rows={report.expense}
                        total={report.expense_total}
                    />
                </div>

                {(report.transfer_in > 0 || report.transfer_out > 0) && (
                    <p className="text-sm text-muted-foreground">
                        Mutasi masuk {formatRupiah(report.transfer_in)} · mutasi
                        keluar {formatRupiah(report.transfer_out)}
                    </p>
                )}

                {report.months.length > 0 && (
                    <section className="space-y-2">
                        <h2 className="text-sm font-medium">Per bulan</h2>
                        <div className="overflow-x-auto rounded-xl border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                                    <tr>
                                        <th className="p-2 font-medium">
                                            Bulan
                                        </th>
                                        <th className="p-2 text-right font-medium">
                                            Pemasukan
                                        </th>
                                        <th className="p-2 text-right font-medium">
                                            Pengeluaran
                                        </th>
                                        <th className="p-2 text-right font-medium">
                                            Selisih
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {report.months.map((row) => (
                                        <tr key={row.month}>
                                            <td className="p-2">{row.month}</td>
                                            <td className="p-2 text-right tabular-nums">
                                                {rupiah.format(row.income)}
                                            </td>
                                            <td className="p-2 text-right tabular-nums">
                                                {rupiah.format(row.expense)}
                                            </td>
                                            <td className="p-2 text-right font-medium tabular-nums">
                                                {formatRupiah(
                                                    row.income - row.expense,
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                )}

                {report.books.length > 0 && (
                    <section className="space-y-2">
                        <h2 className="text-sm font-medium">Per kas</h2>
                        <ul className="divide-y rounded-xl border text-sm">
                            {report.books.map((book) => (
                                <li
                                    key={book.organization_id ?? 'yayasan'}
                                    className="space-y-1 p-3"
                                >
                                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                                        <span className="font-medium">
                                            {book.name}
                                        </span>
                                        <span className="font-semibold tabular-nums">
                                            Saldo akhir{' '}
                                            {formatRupiah(book.closing)}
                                        </span>
                                    </div>
                                    <p className="text-muted-foreground tabular-nums">
                                        Awal {formatRupiah(book.opening)} ·
                                        masuk {formatRupiah(book.income)} ·
                                        keluar {formatRupiah(book.expense)}
                                        {(book.transfer_in > 0 ||
                                            book.transfer_out > 0) &&
                                            ` · mutasi +${rupiah.format(book.transfer_in)} / −${rupiah.format(book.transfer_out)}`}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </>
    );
}

function CategoryTable({
    title,
    rows,
    total,
}: {
    title: string;
    rows: CategoryRow[];
    total: number;
}) {
    return (
        <section className="min-w-0 space-y-2">
            <h2 className="text-sm font-medium">{title}</h2>
            {rows.length === 0 ? (
                <p className="rounded-xl border border-dashed p-4 text-center text-sm text-muted-foreground">
                    Tidak ada transaksi.
                </p>
            ) : (
                <ul className="divide-y rounded-xl border text-sm">
                    {rows.map((row) => (
                        <li
                            key={row.category}
                            className="flex items-center justify-between gap-2 p-2"
                        >
                            <span>{row.category}</span>
                            <span className="tabular-nums">
                                {formatRupiah(row.amount)}
                            </span>
                        </li>
                    ))}
                    <li className="flex items-center justify-between gap-2 bg-muted/50 p-2 font-semibold">
                        <span>Total</span>
                        <span className="tabular-nums">
                            {formatRupiah(total)}
                        </span>
                    </li>
                </ul>
            )}
        </section>
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
