import { Head, Link, router } from '@inertiajs/react';
import { CircleAlert, Download } from 'lucide-react';
import {
    formatBalance,
    formatDate,
    formatNumber,
    selectClass,
} from '@/components/finance/ledger';
import type {
    LedgerPeriod,
    LedgerSettings,
    Option,
    PeriodPreset,
} from '@/components/finance/ledger';
import {
    LedgerFilters,
    ledgerNotice,
} from '@/components/finance/ledger-filters';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

type Row = {
    journal_id: string;
    date: string;
    number: string;
    description: string;
    source_label: string;
    organization: string;
    debit: number;
    credit: number;
    balance: number;
};

type Props = {
    settings: LedgerSettings & { pending: number };
    scopes: Option[];
    scope: string | null;
    period: LedgerPeriod | null;
    presets: PeriodPreset[];
    accounts: Option[];
    account: string | null;
    ledger: {
        account: {
            id: string;
            code: string;
            name: string;
            normal_balance: string;
        };
        opening: number;
        debit: number;
        credit: number;
        closing: number;
        rows: Row[];
        total: number;
        page: number;
        last_page: number;
    } | null;
};

const URL = '/keuangan/pembukuan/buku-besar';

/**
 * Buku besar satu akun (PRD-007 §7, PB-2b): saldo awal, mutasi per jurnal dengan saldo berjalan, saldo akhir.
 */
export default function GeneralLedger({
    settings,
    scopes,
    scope,
    period,
    presets,
    accounts,
    account,
    ledger,
}: Props) {
    const notice = ledgerNotice(settings);
    const normal = ledger?.account.normal_balance ?? 'DEBIT';
    const params = (extra: Record<string, string | null>) => {
        const merged: Record<string, string | null> = {
            akun: account,
            lingkup: scope,
            dari: period?.from ?? null,
            sampai: period?.to ?? null,
            ...extra,
        };
        const clean: Record<string, string> = {};

        for (const [key, value] of Object.entries(merged)) {
            if (value) {
                clean[key] = value;
            }
        }

        return clean;
    };

    return (
        <>
            <Head title="Buku besar" />

            <div className="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-2">
                    <Heading
                        title="Buku besar"
                        description="Mutasi satu akun per jurnal dengan saldo berjalan."
                    />
                    {ledger && (
                        <Button asChild variant="outline">
                            <a
                                href={`${URL}/unduh?${new URLSearchParams(params({}))}`}
                            >
                                <Download />
                                Unduh .xlsx
                            </a>
                        </Button>
                    )}
                </div>

                {!settings.active || period === null ? (
                    <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                        Pembukuan belum aktif.
                    </p>
                ) : (
                    <>
                        {notice && (
                            <p className="flex items-start gap-2 rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100">
                                <CircleAlert className="mt-0.5 size-4 shrink-0" />
                                {notice}
                            </p>
                        )}

                        <div className="grid min-w-0 gap-1.5">
                            <Label htmlFor="gl-account">Akun</Label>
                            <select
                                id="gl-account"
                                className={selectClass}
                                value={account ?? ''}
                                onChange={(event) =>
                                    router.get(
                                        URL,
                                        params({
                                            akun: event.target.value,
                                            halaman: null,
                                        }),
                                        {
                                            preserveScroll: true,
                                        },
                                    )
                                }
                            >
                                {accounts.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <LedgerFilters
                            url={URL}
                            scopes={scopes}
                            scope={scope}
                            period={period}
                            presets={presets}
                            extra={{ akun: account }}
                        />

                        {ledger === null ? (
                            <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                                Pilih akun.
                            </p>
                        ) : (
                            <>
                                <dl className="grid grid-cols-2 gap-3 rounded-xl border p-4 text-sm sm:grid-cols-4">
                                    {[
                                        [
                                            'Saldo awal',
                                            formatBalance(
                                                ledger.opening,
                                                normal,
                                            ),
                                        ],
                                        ['Debit', formatNumber(ledger.debit)],
                                        ['Kredit', formatNumber(ledger.credit)],
                                        [
                                            'Saldo akhir',
                                            formatBalance(
                                                ledger.closing,
                                                normal,
                                            ),
                                        ],
                                    ].map(([label, value]) => (
                                        <div key={label}>
                                            <dt className="text-xs text-muted-foreground">
                                                {label}
                                            </dt>
                                            <dd className="font-semibold tabular-nums">
                                                {value}
                                            </dd>
                                        </div>
                                    ))}
                                </dl>

                                <div className="overflow-x-auto rounded-xl border">
                                    <table className="w-full min-w-[48rem] text-sm">
                                        <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                                            <tr>
                                                <th className="p-2 font-medium">
                                                    Tanggal
                                                </th>
                                                <th className="p-2 font-medium">
                                                    Jurnal
                                                </th>
                                                <th className="p-2 font-medium">
                                                    Lembaga
                                                </th>
                                                <th className="p-2 text-right font-medium">
                                                    Debit
                                                </th>
                                                <th className="p-2 text-right font-medium">
                                                    Kredit
                                                </th>
                                                <th className="p-2 text-right font-medium">
                                                    Saldo
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y">
                                            {ledger.page === 1 && (
                                                <tr className="bg-muted/30">
                                                    <td
                                                        className="p-2"
                                                        colSpan={5}
                                                    >
                                                        Saldo awal
                                                    </td>
                                                    <td className="p-2 text-right font-medium tabular-nums">
                                                        {formatBalance(
                                                            ledger.opening,
                                                            normal,
                                                        )}
                                                    </td>
                                                </tr>
                                            )}
                                            {ledger.rows.length === 0 && (
                                                <tr>
                                                    <td
                                                        colSpan={6}
                                                        className="p-6 text-center text-muted-foreground"
                                                    >
                                                        Tidak ada mutasi pada
                                                        periode ini.
                                                    </td>
                                                </tr>
                                            )}
                                            {ledger.rows.map((row, index) => (
                                                <tr
                                                    key={`${row.journal_id}-${index}`}
                                                >
                                                    <td className="p-2 whitespace-nowrap">
                                                        {formatDate(row.date)}
                                                    </td>
                                                    <td className="p-2">
                                                        <Link
                                                            href={`/keuangan/pembukuan/jurnal/${row.journal_id}`}
                                                            className="underline-offset-4 hover:underline"
                                                        >
                                                            {row.description}
                                                        </Link>
                                                        <span className="block text-xs text-muted-foreground">
                                                            <span className="font-mono">
                                                                {row.number}
                                                            </span>{' '}
                                                            · {row.source_label}
                                                        </span>
                                                    </td>
                                                    <td className="p-2">
                                                        {row.organization}
                                                    </td>
                                                    <td className="p-2 text-right tabular-nums">
                                                        {row.debit
                                                            ? formatNumber(
                                                                  row.debit,
                                                              )
                                                            : ''}
                                                    </td>
                                                    <td className="p-2 text-right tabular-nums">
                                                        {row.credit
                                                            ? formatNumber(
                                                                  row.credit,
                                                              )
                                                            : ''}
                                                    </td>
                                                    <td className="p-2 text-right tabular-nums">
                                                        {formatBalance(
                                                            row.balance,
                                                            normal,
                                                        )}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>

                                {ledger.last_page > 1 && (
                                    <nav
                                        aria-label="Halaman"
                                        className="flex flex-wrap items-center justify-between gap-2 text-sm"
                                    >
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            disabled={ledger.page <= 1}
                                            onClick={() =>
                                                router.get(
                                                    URL,
                                                    params({
                                                        halaman: String(
                                                            ledger.page - 1,
                                                        ),
                                                    }),
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            Sebelumnya
                                        </Button>
                                        <span className="text-muted-foreground">
                                            Halaman {ledger.page} dari{' '}
                                            {ledger.last_page} · {ledger.total}{' '}
                                            baris
                                        </span>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            disabled={
                                                ledger.page >= ledger.last_page
                                            }
                                            onClick={() =>
                                                router.get(
                                                    URL,
                                                    params({
                                                        halaman: String(
                                                            ledger.page + 1,
                                                        ),
                                                    }),
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            Berikutnya
                                        </Button>
                                    </nav>
                                )}
                                <p className="text-xs text-muted-foreground">
                                    Saldo mengikuti saldo normal akun (
                                    {normal === 'DEBIT' ? 'debit' : 'kredit'});
                                    angka dalam kurung berarti berlawanan.
                                </p>
                            </>
                        )}
                    </>
                )}
            </div>
        </>
    );
}
