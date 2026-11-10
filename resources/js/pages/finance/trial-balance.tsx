import { Head, Link } from '@inertiajs/react';
import { CircleAlert, Download } from 'lucide-react';
import { Fragment, useState } from 'react';
import { formatBalance, formatNumber } from '@/components/finance/ledger';
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

type Row = {
    id: string;
    code: string;
    name: string;
    group: string;
    normal_balance: 'DEBIT' | 'KREDIT';
    opening: number;
    debit: number;
    credit: number;
    closing: number;
};

type Props = {
    settings: LedgerSettings & { pending: number };
    scopes: Option[];
    scope: string | null;
    period: LedgerPeriod | null;
    presets: PeriodPreset[];
    report: {
        rows: Row[];
        totals: {
            opening_debit: number;
            opening_credit: number;
            debit: number;
            credit: number;
            closing_debit: number;
            closing_credit: number;
        };
    } | null;
};

const URL = '/keuangan/pembukuan/neraca-saldo';

const GROUPS: Record<string, string> = {
    ASET: 'Aset',
    LIABILITAS: 'Liabilitas',
    ASET_NETO: 'Aset neto',
    PENDAPATAN: 'Pendapatan',
    BEBAN: 'Beban',
};

/**
 * Neraca saldo (PRD-007 §7, PB-2b): saldo awal, mutasi debit/kredit, saldo akhir semua akun untuk lingkup & periode.
 * Saldo ditampilkan menurut saldo normal akun; kurung = berlawanan dengan saldo normal.
 */
export default function TrialBalance({
    settings,
    scopes,
    scope,
    period,
    presets,
    report,
}: Props) {
    const [showEmpty, setShowEmpty] = useState(false);
    const notice = ledgerNotice(settings);
    const rows = (report?.rows ?? []).filter(
        (row) =>
            showEmpty ||
            row.opening !== 0 ||
            row.debit !== 0 ||
            row.credit !== 0,
    );
    const query = (extra: Record<string, string>) =>
        new URLSearchParams({
            ...(scope ? { lingkup: scope } : {}),
            ...(period ? { dari: period.from, sampai: period.to } : {}),
            ...extra,
        }).toString();

    return (
        <>
            <Head title="Neraca saldo" />

            <div className="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-2">
                    <Heading
                        title="Neraca saldo"
                        description="Saldo semua akun pada periode terpilih. Total debit selalu sama dengan total kredit."
                    />
                    {report && (
                        <Button asChild variant="outline">
                            <a href={`${URL}/unduh?${query({})}`}>
                                <Download />
                                Unduh .xlsx
                            </a>
                        </Button>
                    )}
                </div>

                {!settings.active || period === null || report === null ? (
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

                        <LedgerFilters
                            url={URL}
                            scopes={scopes}
                            scope={scope}
                            period={period}
                            presets={presets}
                        />

                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                checked={showEmpty}
                                onChange={(event) =>
                                    setShowEmpty(event.target.checked)
                                }
                            />
                            Tampilkan akun bersaldo nol
                        </label>

                        <div className="overflow-x-auto rounded-xl border">
                            <table className="w-full min-w-[44rem] text-sm">
                                <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                                    <tr>
                                        <th className="p-2 font-medium">
                                            Akun
                                        </th>
                                        <th className="p-2 text-right font-medium">
                                            Saldo awal
                                        </th>
                                        <th className="p-2 text-right font-medium">
                                            Debit
                                        </th>
                                        <th className="p-2 text-right font-medium">
                                            Kredit
                                        </th>
                                        <th className="p-2 text-right font-medium">
                                            Saldo akhir
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {rows.length === 0 && (
                                        <tr>
                                            <td
                                                colSpan={5}
                                                className="p-6 text-center text-muted-foreground"
                                            >
                                                Belum ada saldo pada periode
                                                ini.
                                            </td>
                                        </tr>
                                    )}
                                    {rows.map((row, index) => (
                                        <Fragment key={row.id}>
                                            {(index === 0 ||
                                                rows[index - 1].group !==
                                                    row.group) && (
                                                <tr className="bg-muted/30">
                                                    <td
                                                        colSpan={5}
                                                        className="p-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                                                    >
                                                        {GROUPS[row.group] ??
                                                            row.group}
                                                    </td>
                                                </tr>
                                            )}
                                            <tr>
                                                <td className="p-2">
                                                    <Link
                                                        href={`/keuangan/pembukuan/buku-besar?${query({ akun: row.id })}`}
                                                        className="underline-offset-4 hover:underline"
                                                    >
                                                        <span className="font-mono text-xs text-muted-foreground">
                                                            {row.code}
                                                        </span>{' '}
                                                        {row.name}
                                                    </Link>
                                                </td>
                                                <td className="p-2 text-right tabular-nums">
                                                    {formatBalance(
                                                        row.opening,
                                                        row.normal_balance,
                                                    )}
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
                                                <td className="p-2 text-right font-medium tabular-nums">
                                                    {formatBalance(
                                                        row.closing,
                                                        row.normal_balance,
                                                    )}
                                                </td>
                                            </tr>
                                        </Fragment>
                                    ))}
                                </tbody>
                                <tfoot className="border-t bg-muted/30 text-xs font-medium">
                                    <tr>
                                        <td className="p-2">
                                            Total saldo debit / kredit
                                        </td>
                                        <td className="p-2 text-right tabular-nums">
                                            {formatNumber(
                                                report.totals.opening_debit,
                                            )}{' '}
                                            /{' '}
                                            {formatNumber(
                                                report.totals.opening_credit,
                                            )}
                                        </td>
                                        <td className="p-2 text-right tabular-nums">
                                            {formatNumber(report.totals.debit)}
                                        </td>
                                        <td className="p-2 text-right tabular-nums">
                                            {formatNumber(report.totals.credit)}
                                        </td>
                                        <td className="p-2 text-right tabular-nums">
                                            {formatNumber(
                                                report.totals.closing_debit,
                                            )}{' '}
                                            /{' '}
                                            {formatNumber(
                                                report.totals.closing_credit,
                                            )}
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        <p className="text-xs text-muted-foreground">
                            Saldo mengikuti saldo normal akun; angka dalam
                            kurung berarti berlawanan dengan saldo normalnya.
                            Pendapatan & beban dihitung sejak awal tahun buku;
                            hasil tahun buku sebelumnya sudah masuk Aset neto.
                            Klik nama akun untuk membuka buku besarnya.
                        </p>
                    </>
                )}
            </div>
        </>
    );
}
