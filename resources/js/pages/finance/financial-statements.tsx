import { Head } from '@inertiajs/react';
import { CircleAlert, Download } from 'lucide-react';
import { Fragment } from 'react';
import type { ReactNode } from 'react';
import { formatDate, formatNumber } from '@/components/finance/ledger';
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
import { PageTabs, usePageTab } from '@/components/page-tabs';
import { Button } from '@/components/ui/button';

type Pair = { current: number; previous: number | null };

type Section = {
    title: string;
    lines: {
        code: string;
        name: string;
        current: number;
        previous: number | null;
    }[];
    current: number;
    previous: number | null;
};

type Position = {
    date: string;
    previous_date: string | null;
    assets: Section[];
    liabilities: Section[];
    net_assets: { unrestricted: Pair; restricted: Pair };
    total_assets: Pair;
    total_liabilities: Pair;
    total_net_assets: Pair;
};

type Activities = {
    from: string;
    to: string;
    previous_from: string | null;
    previous_to: string | null;
    unrestricted: {
        revenue: Section[];
        released: Pair;
        revenue_total: Pair;
        expenses: Section[];
        expense_total: Pair;
        surplus: Pair;
    };
    restricted: { revenue: Section[]; released: Pair; change: Pair };
    total: Pair;
};

type ChangeRow = {
    opening: number;
    change: number;
    other: number;
    closing: number;
};

type CashFlows = {
    activities: {
        activity: string;
        label: string;
        receipts: { code: string; name: string; amount: number }[];
        payments: { code: string; name: string; amount: number }[];
        net: number;
    }[];
    net_change: number;
    new_accounts: number;
    opening: number;
    closing: number;
    custody: { opening: number; closing: number };
};

type Props = {
    settings: LedgerSettings & { pending: number };
    scopes: Option[];
    scope: string | null;
    period: LedgerPeriod | null;
    presets: PeriodPreset[];
    entity: string;
    position: Position | null;
    activities: Activities | null;
    changes: {
        unrestricted: ChangeRow;
        restricted: ChangeRow;
        total: ChangeRow;
    } | null;
    cashFlows: CashFlows | null;
};

const URL = '/keuangan/pembukuan/laporan';

const TABS = ['posisi', 'penghasilan', 'perubahan', 'arus-kas'] as const;

/** Angka laporan: negatif dalam kurung, nol = "-". */
const money = (value: number | null) =>
    value === null
        ? ''
        : value === 0
          ? '-'
          : value < 0
            ? `(${formatNumber(-value)})`
            : formatNumber(value);

/**
 * Laporan keuangan format ISAK 35 (PRD-007 §7, PB-3): posisi keuangan, penghasilan komprehensif, perubahan aset neto,
 * arus kas metode langsung — per lingkup & periode, dengan kolom pembanding.
 */
export default function FinancialStatements({
    settings,
    scopes,
    scope,
    period,
    presets,
    entity,
    position,
    activities,
    changes,
    cashFlows,
}: Props) {
    const [tab, setTab] = usePageTab(TABS, 'posisi');
    const notice = ledgerNotice(settings);
    const query = new URLSearchParams({
        ...(scope ? { lingkup: scope } : {}),
        ...(period ? { dari: period.from, sampai: period.to } : {}),
    }).toString();

    return (
        <>
            <Head title="Laporan keuangan" />

            <div className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-2">
                    <Heading
                        title="Laporan keuangan"
                        description="Format ISAK 35 entitas nirlaba, disusun otomatis dari jurnal."
                    />
                    {period && (
                        <Button asChild variant="outline">
                            <a href={`${URL}/unduh?${query}`}>
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

                        <LedgerFilters
                            url={URL}
                            scopes={scopes}
                            scope={scope}
                            period={period}
                            presets={presets}
                            extra={{ tab }}
                        />

                        <PageTabs
                            label="Jenis laporan"
                            tabs={[
                                { value: 'posisi', label: 'Posisi keuangan' },
                                {
                                    value: 'penghasilan',
                                    label: 'Penghasilan komprehensif',
                                },
                                {
                                    value: 'perubahan',
                                    label: 'Perubahan aset neto',
                                },
                                { value: 'arus-kas', label: 'Arus kas' },
                            ]}
                            value={tab}
                            onChange={setTab}
                        />

                        <p className="text-sm">
                            <span className="font-semibold">{entity}</span>
                        </p>

                        {tab === 'posisi' && position && (
                            <PositionReport report={position} />
                        )}
                        {tab === 'penghasilan' && activities && (
                            <ActivitiesReport report={activities} />
                        )}
                        {tab === 'perubahan' && changes && (
                            <ChangesReport report={changes} period={period} />
                        )}
                        {tab === 'arus-kas' && cashFlows && (
                            <CashFlowReport
                                report={cashFlows}
                                period={period}
                            />
                        )}
                    </>
                )}
            </div>
        </>
    );
}

function Statement({
    title,
    columns,
    children,
}: {
    title: string;
    columns: string[];
    children: ReactNode;
}) {
    return (
        <section className="space-y-2">
            <h2 className="text-sm font-medium">{title}</h2>
            <div className="overflow-x-auto rounded-xl border">
                <table className="w-full min-w-[32rem] text-sm">
                    <thead className="bg-muted/50 text-xs text-muted-foreground">
                        <tr>
                            <th className="p-2 text-left font-medium" />
                            {columns.map((column) => (
                                <th
                                    key={column}
                                    className="p-2 text-right font-medium"
                                >
                                    {column}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody className="divide-y">{children}</tbody>
                </table>
            </div>
        </section>
    );
}

function GroupRow({ children, span }: { children: ReactNode; span: number }) {
    return (
        <tr className="bg-muted/30">
            <td
                colSpan={span}
                className="p-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
            >
                {children}
            </td>
        </tr>
    );
}

function Amounts({
    label,
    values,
    strong = false,
    indent = 0,
}: {
    label: ReactNode;
    values: (number | null)[];
    strong?: boolean;
    indent?: number;
}) {
    return (
        <tr className={strong ? 'font-semibold' : ''}>
            <td className="p-2" style={{ paddingLeft: `${0.5 + indent}rem` }}>
                {label}
            </td>
            {values.map((value, index) => (
                <td key={index} className="p-2 text-right tabular-nums">
                    {money(value)}
                </td>
            ))}
        </tr>
    );
}

function Sections({
    sections,
    comparative,
}: {
    sections: Section[];
    comparative: boolean;
}) {
    const span = comparative ? 3 : 2;

    if (sections.length === 0) {
        return (
            <tr>
                <td colSpan={span} className="p-2 text-muted-foreground">
                    Tidak ada saldo.
                </td>
            </tr>
        );
    }

    return (
        <>
            {sections.map((section) => (
                <Fragment key={section.title}>
                    <tr>
                        <td colSpan={span} className="p-2 pl-4 font-medium">
                            {section.title}
                        </td>
                    </tr>
                    {section.lines.map((line) => (
                        <Amounts
                            key={line.code}
                            indent={1.5}
                            label={
                                <>
                                    <span className="font-mono text-xs text-muted-foreground">
                                        {line.code}
                                    </span>{' '}
                                    {line.name}
                                </>
                            }
                            values={
                                comparative
                                    ? [line.current, line.previous]
                                    : [line.current]
                            }
                        />
                    ))}
                </Fragment>
            ))}
        </>
    );
}

function PositionReport({ report }: { report: Position }) {
    const comparative = report.previous_date !== null;
    const pick = (pair: Pair) =>
        comparative ? [pair.current, pair.previous] : [pair.current];
    const span = comparative ? 3 : 2;
    const liabilitiesAndNet: Pair = {
        current:
            report.total_liabilities.current + report.total_net_assets.current,
        previous:
            report.total_liabilities.previous === null
                ? null
                : report.total_liabilities.previous +
                  (report.total_net_assets.previous ?? 0),
    };
    const balanced = report.total_assets.current === liabilitiesAndNet.current;

    return (
        <>
            <Statement
                title={`Laporan posisi keuangan per ${formatDate(report.date)}`}
                columns={[
                    formatDate(report.date),
                    ...(report.previous_date
                        ? [formatDate(report.previous_date)]
                        : []),
                ]}
            >
                <GroupRow span={span}>Aset</GroupRow>
                <Sections sections={report.assets} comparative={comparative} />
                <Amounts
                    label="Jumlah aset"
                    values={pick(report.total_assets)}
                    strong
                />
                <GroupRow span={span}>Liabilitas</GroupRow>
                <Sections
                    sections={report.liabilities}
                    comparative={comparative}
                />
                <Amounts
                    label="Jumlah liabilitas"
                    values={pick(report.total_liabilities)}
                    strong
                />
                <GroupRow span={span}>Aset neto</GroupRow>
                <Amounts
                    label="Aset neto tanpa pembatasan"
                    values={pick(report.net_assets.unrestricted)}
                    indent={1}
                />
                <Amounts
                    label="Aset neto dengan pembatasan"
                    values={pick(report.net_assets.restricted)}
                    indent={1}
                />
                <Amounts
                    label="Jumlah aset neto"
                    values={pick(report.total_net_assets)}
                    strong
                />
                <Amounts
                    label="Jumlah liabilitas dan aset neto"
                    values={pick(liabilitiesAndNet)}
                    strong
                />
            </Statement>
            {!balanced && (
                <p className="text-sm text-destructive">
                    Jumlah aset belum sama dengan liabilitas + aset neto.
                    Periksa Cek kecocokan dan susun ulang jurnal.
                </p>
            )}
            <p className="text-xs text-muted-foreground">
                Aset neto sudah termasuk surplus/defisit sampai tanggal laporan.
                Kolom pembanding = akhir tahun buku lalu (bila sudah dalam
                periode pembukuan). Angka dalam kurung = negatif.
            </p>
        </>
    );
}

function ActivitiesReport({ report }: { report: Activities }) {
    const comparative = report.previous_from !== null;
    const pick = (pair: Pair) =>
        comparative ? [pair.current, pair.previous] : [pair.current];
    const span = comparative ? 3 : 2;

    return (
        <Statement
            title={`Laporan penghasilan komprehensif ${formatDate(report.from)} s.d. ${formatDate(report.to)}`}
            columns={[
                'Periode ini',
                ...(comparative && report.previous_from && report.previous_to
                    ? [
                          `${formatDate(report.previous_from)} – ${formatDate(report.previous_to)}`,
                      ]
                    : []),
            ]}
        >
            <GroupRow span={span}>
                Tanpa pembatasan dari pemberi sumber daya
            </GroupRow>
            <tr>
                <td colSpan={span} className="p-2 font-medium">
                    Pendapatan
                </td>
            </tr>
            <Sections
                sections={report.unrestricted.revenue}
                comparative={comparative}
            />
            {(report.unrestricted.released.current !== 0 ||
                (report.unrestricted.released.previous ?? 0) !== 0) && (
                <Amounts
                    label="Aset neto yang dibebaskan dari pembatasan"
                    values={pick(report.unrestricted.released)}
                    indent={1.5}
                />
            )}
            <Amounts
                label="Jumlah pendapatan"
                values={pick(report.unrestricted.revenue_total)}
                strong
            />
            <tr>
                <td colSpan={span} className="p-2 font-medium">
                    Beban
                </td>
            </tr>
            <Sections
                sections={report.unrestricted.expenses}
                comparative={comparative}
            />
            <Amounts
                label="Jumlah beban"
                values={pick(report.unrestricted.expense_total)}
                strong
            />
            <Amounts
                label="Surplus (defisit)"
                values={pick(report.unrestricted.surplus)}
                strong
            />
            <GroupRow span={span}>
                Dengan pembatasan dari pemberi sumber daya
            </GroupRow>
            <Sections
                sections={report.restricted.revenue}
                comparative={comparative}
            />
            {(report.restricted.released.current !== 0 ||
                (report.restricted.released.previous ?? 0) !== 0) && (
                <Amounts
                    label="Aset neto yang dibebaskan dari pembatasan"
                    values={pick(report.restricted.released)}
                    indent={1.5}
                />
            )}
            <Amounts
                label="Kenaikan (penurunan) aset neto dengan pembatasan"
                values={pick(report.restricted.change)}
                strong
            />
            <Amounts
                label="Total penghasilan komprehensif"
                values={pick(report.total)}
                strong
            />
        </Statement>
    );
}

function ChangesReport({
    report,
    period,
}: {
    report: {
        unrestricted: ChangeRow;
        restricted: ChangeRow;
        total: ChangeRow;
    };
    period: LedgerPeriod;
}) {
    const rows: [keyof ChangeRow, string][] = [
        ['opening', `Saldo awal (${formatDate(period.from)})`],
        ['change', 'Surplus (defisit) / perubahan periode'],
        ['other', 'Penyesuaian langsung lain'],
        ['closing', `Saldo akhir (${formatDate(period.to)})`],
    ];

    return (
        <>
            <Statement
                title={`Laporan perubahan aset neto ${formatDate(period.from)} s.d. ${formatDate(period.to)}`}
                columns={['Tanpa pembatasan', 'Dengan pembatasan', 'Jumlah']}
            >
                {rows.map(([key, label]) => (
                    <Amounts
                        key={key}
                        label={label}
                        values={[
                            report.unrestricted[key],
                            report.restricted[key],
                            report.total[key],
                        ]}
                        strong={key === 'closing'}
                    />
                ))}
            </Statement>
            <p className="text-xs text-muted-foreground">
                "Penyesuaian langsung lain" = jurnal manual yang langsung
                mengubah akun aset neto (selain pelepasan pembatasan).
            </p>
        </>
    );
}

function CashFlowReport({
    report,
    period,
}: {
    report: CashFlows;
    period: LedgerPeriod;
}) {
    return (
        <>
            <Statement
                title={`Laporan arus kas (metode langsung) ${formatDate(period.from)} s.d. ${formatDate(period.to)}`}
                columns={['Jumlah']}
            >
                {report.activities.map((activity) => (
                    <Fragment key={activity.activity}>
                        <GroupRow span={2}>{activity.label}</GroupRow>
                        {activity.receipts.length === 0 &&
                            activity.payments.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={2}
                                        className="p-2 text-muted-foreground"
                                    >
                                        Tidak ada arus kas.
                                    </td>
                                </tr>
                            )}
                        {activity.receipts.map((line) => (
                            <Amounts
                                key={`${activity.activity}-in-${line.code}`}
                                label={`Penerimaan — ${line.name}`}
                                values={[line.amount]}
                                indent={1}
                            />
                        ))}
                        {activity.payments.map((line) => (
                            <Amounts
                                key={`${activity.activity}-out-${line.code}`}
                                label={`Pengeluaran — ${line.name}`}
                                values={[-line.amount]}
                                indent={1}
                            />
                        ))}
                        <Amounts
                            label="Kas bersih"
                            values={[activity.net]}
                            strong
                        />
                    </Fragment>
                ))}
                <Amounts
                    label="Kenaikan (penurunan) kas"
                    values={[report.net_change]}
                    strong
                />
                {report.new_accounts !== 0 && (
                    <Amounts
                        label="Saldo awal rekening kas baru"
                        values={[report.new_accounts]}
                    />
                )}
                <Amounts
                    label="Kas & bank awal periode"
                    values={[report.opening]}
                />
                <Amounts
                    label="Kas & bank akhir periode"
                    values={[report.closing]}
                    strong
                />
            </Statement>
            <p className="text-xs text-muted-foreground">
                Kas titipan tabungan siswa dipisah (bukan milik yayasan): awal{' '}
                {money(report.custody.opening)}, akhir{' '}
                {money(report.custody.closing)}. Mutasi antar-rekening kas tidak
                dihitung sebagai arus kas.
            </p>
        </>
    );
}
