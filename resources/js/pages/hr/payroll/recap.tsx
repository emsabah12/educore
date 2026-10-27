import { Form, Head, Link, router } from '@inertiajs/react';
import { ChevronDown, Download, Lock, LockOpen, Trash2 } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { formatRupiah } from '@/components/hr/pay-format';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Option = { value: string; label: string };

type Line = {
    group: string;
    label: string;
    detail: string | null;
    kind: 'PENDAPATAN' | 'POTONGAN';
    amount: number;
};

type Row = {
    employee_id: string;
    name: string;
    employee_number: string | null;
    organization: string | null;
    columns: {
        position: number;
        fixed: number;
        present_day: number;
        teaching: number;
        adjustment_earning: number;
        fixed_deduction: number;
        attendance_deduction: number;
        adjustment_deduction: number;
    };
    earning: number;
    deduction: number;
    net: number;
    lines: Line[];
    warnings: string[];
    adjustments: {
        id: string;
        kind: 'PENDAPATAN' | 'POTONGAN';
        amount: number;
        note: string;
    }[];
};

type Props = {
    month: string;
    maxMonth: string;
    label: string;
    until: string;
    lock: {
        locked_at: string;
        locked_by: string | null;
        until: string;
        employee_count: number;
    } | null;
    organization: string | null;
    organizations: Option[];
    rows: Row[];
    totals: { earning: number; deduction: number; net: number };
};

const BASE_URL = '/pegawai/gaji/rekap';

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';

const COLUMN_LABELS: [keyof Row['columns'], string][] = [
    ['position', 'Dari jabatan'],
    ['fixed', 'Komponen tetap'],
    ['present_day', 'Per hari hadir'],
    ['teaching', 'Honor JP'],
    ['adjustment_earning', 'Penyesuaian (+)'],
    ['fixed_deduction', 'Potongan tetap'],
    ['attendance_deduction', 'Potongan presensi'],
    ['adjustment_deduction', 'Penyesuaian (−)'],
];

/**
 * Rekap gaji bulanan (PRD-001 §16.3, HR-5c-1). Belum dikunci = dihitung dari data terkini; terkunci =
 * snapshot. Hanya Admin SDM / Admin Yayasan; server tetap memeriksa ulang.
 */
export default function PayrollRecap(props: Props) {
    const locked = props.lock !== null;
    const query = `bulan=${props.month}${props.organization ? `&lembaga=${props.organization}` : ''}`;

    const go = (next: { bulan?: string; lembaga?: string | null }) =>
        router.get(
            BASE_URL,
            {
                bulan: next.bulan ?? props.month,
                lembaga:
                    (next.lembaga === undefined
                        ? props.organization
                        : next.lembaga) || undefined,
            },
            { preserveState: false, replace: true },
        );

    return (
        <>
            <Head title="Rekap gaji" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <Heading
                        title="Rekap gaji"
                        description="Data input gaji per pegawai untuk Keuangan. Kunci bulan bila sudah benar: angkanya tidak berubah lagi walau tarif, jabatan, jadwal, atau presensi diubah."
                    />
                    <div className="grid grid-cols-[repeat(auto-fit,minmax(10rem,1fr))] gap-2 sm:flex">
                        <Button variant="outline" asChild>
                            <a href={`${BASE_URL}/unduh?${query}`}>
                                <Download />
                                Unduh .xlsx
                            </a>
                        </Button>
                        <Button variant="outline" asChild>
                            <Link href="/pegawai/gaji">Gaji pegawai</Link>
                        </Button>
                    </div>
                </div>

                <div className="grid grid-cols-[repeat(auto-fit,minmax(12rem,1fr))] gap-3 rounded-xl border p-4">
                    <div className="grid gap-1.5">
                        <Label htmlFor="bulan">Bulan</Label>
                        <Input
                            id="bulan"
                            type="month"
                            value={props.month}
                            max={props.maxMonth}
                            onChange={(event) =>
                                event.target.value &&
                                go({ bulan: event.target.value })
                            }
                        />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="lembaga">Lembaga</Label>
                        <select
                            id="lembaga"
                            className={selectClass}
                            value={props.organization ?? ''}
                            onChange={(event) =>
                                go({ lembaga: event.target.value || null })
                            }
                        >
                            <option value="">Semua lembaga</option>
                            {props.organizations.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </div>
                </div>

                <LockPanel {...props} />

                <div className="grid grid-cols-[repeat(auto-fit,minmax(11rem,1fr))] gap-3 rounded-xl bg-muted/40 p-4 text-sm">
                    <Total label="Pegawai" text={String(props.rows.length)} />
                    <Total
                        label="Total pendapatan"
                        text={formatRupiah(props.totals.earning)}
                    />
                    <Total
                        label="Total potongan"
                        text={formatRupiah(props.totals.deduction)}
                    />
                    <Total
                        label="Total bersih"
                        text={formatRupiah(props.totals.net)}
                    />
                </div>

                {props.rows.length === 0 ? (
                    <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                        Tidak ada pegawai aktif
                        {props.organization ? ' di lembaga ini' : ''}.
                    </p>
                ) : (
                    <ul className="divide-y rounded-xl border">
                        {props.rows.map((row) => (
                            <RecapRow
                                key={row.employee_id}
                                row={row}
                                month={props.month}
                                locked={locked}
                            />
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}

function LockPanel(props: Props) {
    const [unlocking, setUnlocking] = useState(false);
    const [confirming, setConfirming] = useState(false);

    if (props.lock) {
        return (
            <div className="space-y-3 rounded-xl border border-emerald-500/50 bg-emerald-50 p-4 text-sm dark:bg-emerald-950/30">
                <p className="flex flex-wrap items-center gap-2">
                    <Badge variant="secondary">
                        <Lock />
                        Terkunci
                    </Badge>
                    {props.label} dikunci {props.lock.locked_at}
                    {props.lock.locked_by && ` oleh ${props.lock.locked_by}`},
                    dihitung s.d. {props.lock.until},{' '}
                    {props.lock.employee_count} pegawai.
                </p>
                {unlocking ? (
                    <Form
                        action={`${BASE_URL}/${props.month}/buka-kunci`}
                        method="post"
                        errorBag="unlock"
                        options={{ preserveScroll: true }}
                        onSuccess={() => setUnlocking(false)}
                        className="space-y-2"
                    >
                        {({ processing, errors }) => (
                            <>
                                <Label htmlFor="unlock-reason">
                                    Alasan buka kunci
                                </Label>
                                <Input
                                    id="unlock-reason"
                                    name="reason"
                                    maxLength={300}
                                    placeholder="mis. Ada koreksi presensi"
                                    required
                                />
                                <InputError
                                    message={errors.reason ?? errors.form}
                                />
                                <div className="grid grid-cols-[repeat(auto-fit,minmax(9rem,1fr))] gap-2 sm:flex">
                                    <Button
                                        type="submit"
                                        variant="destructive"
                                        disabled={processing}
                                    >
                                        Buka kunci
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => setUnlocking(false)}
                                    >
                                        Batal
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                ) : (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => setUnlocking(true)}
                    >
                        <LockOpen />
                        Buka kunci
                    </Button>
                )}
            </div>
        );
    }

    return (
        <div className="space-y-3 rounded-xl border border-amber-400/60 bg-amber-50 p-4 text-sm dark:bg-amber-950/30">
            <p>
                <strong>Belum dikunci.</strong> Angka dihitung dari data terkini
                {props.month === props.maxMonth &&
                    ` (presensi & jam hidup s.d. ${props.until})`}
                . Periksa peringatan di tiap pegawai sebelum mengunci.
            </p>
            {confirming ? (
                <Form
                    action={`${BASE_URL}/${props.month}/kunci`}
                    method="post"
                    errorBag="lock"
                    options={{ preserveScroll: true }}
                    onSuccess={() => setConfirming(false)}
                    className="space-y-2"
                >
                    {({ processing, errors }) => (
                        <>
                            <p>
                                Kunci {props.label} untuk{' '}
                                <strong>semua lembaga</strong>? Penyesuaian
                                tidak bisa diubah sampai kunci dibuka.
                            </p>
                            <InputError message={errors.form} />
                            <div className="grid grid-cols-[repeat(auto-fit,minmax(9rem,1fr))] gap-2 sm:flex">
                                <Button type="submit" disabled={processing}>
                                    <Lock />
                                    Ya, kunci
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setConfirming(false)}
                                >
                                    Batal
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            ) : (
                <Button
                    type="button"
                    size="sm"
                    onClick={() => setConfirming(true)}
                >
                    <Lock />
                    Kunci {props.label}
                </Button>
            )}
        </div>
    );
}

function RecapRow({
    row,
    month,
    locked,
}: {
    row: Row;
    month: string;
    locked: boolean;
}) {
    const [open, setOpen] = useState(false);

    return (
        <li className="text-sm">
            <button
                type="button"
                onClick={() => setOpen(!open)}
                aria-expanded={open}
                className="flex w-full flex-col gap-2 p-3 text-left hover:bg-muted/50 sm:flex-row sm:items-center sm:justify-between"
            >
                <span className="min-w-0">
                    <span className="flex flex-wrap items-center gap-2 font-medium break-words">
                        {row.name}
                        {row.warnings.length > 0 && (
                            <Badge className="bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-100">
                                {row.warnings.length} peringatan
                            </Badge>
                        )}
                    </span>
                    <span className="block text-xs text-muted-foreground">
                        {[row.employee_number, row.organization]
                            .filter(Boolean)
                            .join(' · ') || 'Tanpa penempatan utama'}
                    </span>
                </span>
                <span className="flex shrink-0 items-center gap-3">
                    <span className="grid grid-cols-3 gap-3 text-right text-xs">
                        <span>
                            <span className="block text-muted-foreground">
                                Pendapatan
                            </span>
                            <span className="tabular-nums">
                                {formatRupiah(row.earning)}
                            </span>
                        </span>
                        <span>
                            <span className="block text-muted-foreground">
                                Potongan
                            </span>
                            <span className="tabular-nums">
                                {formatRupiah(row.deduction)}
                            </span>
                        </span>
                        <span>
                            <span className="block text-muted-foreground">
                                Bersih
                            </span>
                            <span className="font-medium tabular-nums">
                                {formatRupiah(row.net)}
                            </span>
                        </span>
                    </span>
                    <ChevronDown
                        className={
                            'size-4 transition-transform ' +
                            (open ? 'rotate-180' : '')
                        }
                    />
                </span>
            </button>

            {open && (
                <div className="space-y-3 border-t bg-muted/20 p-3">
                    {row.warnings.length > 0 && (
                        <ul className="list-inside list-disc text-xs text-amber-800 dark:text-amber-200">
                            {row.warnings.map((warning) => (
                                <li key={warning}>{warning}</li>
                            ))}
                        </ul>
                    )}

                    <dl className="grid grid-cols-[repeat(auto-fit,minmax(9rem,1fr))] gap-2 text-xs">
                        {COLUMN_LABELS.map(([key, label]) => (
                            <div key={key}>
                                <dt className="text-muted-foreground">
                                    {label}
                                </dt>
                                <dd className="tabular-nums">
                                    {formatRupiah(row.columns[key])}
                                </dd>
                            </div>
                        ))}
                    </dl>

                    {row.lines.length > 0 && (
                        <ul className="divide-y rounded-lg border bg-background">
                            {row.lines.map((line, index) => (
                                <li
                                    key={index}
                                    className="flex flex-wrap items-center justify-between gap-2 p-2"
                                >
                                    <span className="min-w-0 break-words">
                                        {line.label}
                                        {line.detail && (
                                            <span className="block text-xs text-muted-foreground">
                                                {line.detail}
                                            </span>
                                        )}
                                    </span>
                                    <span
                                        className={
                                            'tabular-nums ' +
                                            (line.kind === 'POTONGAN'
                                                ? 'text-red-700 dark:text-red-400'
                                                : '')
                                        }
                                    >
                                        {line.kind === 'POTONGAN' && '− '}
                                        {formatRupiah(line.amount)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}

                    <Adjustments row={row} month={month} locked={locked} />

                    <Link
                        href={`/pegawai/gaji/${row.employee_id}?bulan=${month}`}
                        className="inline-block text-xs font-medium underline underline-offset-4"
                    >
                        Setup gaji pegawai ini →
                    </Link>
                </div>
            )}
        </li>
    );
}

function Adjustments({
    row,
    month,
    locked,
}: {
    row: Row;
    month: string;
    locked: boolean;
}) {
    const baseUrl = `${BASE_URL}/${month}/penyesuaian`;

    return (
        <div className="space-y-2">
            <p className="text-xs font-medium">Penyesuaian manual</p>
            {row.adjustments.length === 0 ? (
                <p className="text-xs text-muted-foreground">
                    Belum ada penyesuaian.
                </p>
            ) : (
                <ul className="divide-y rounded-lg border bg-background">
                    {row.adjustments.map((adjustment) => (
                        <li
                            key={adjustment.id}
                            className="flex flex-wrap items-center justify-between gap-2 p-2"
                        >
                            <span className="min-w-0 break-words">
                                {adjustment.note}
                            </span>
                            <span className="flex items-center gap-1">
                                <span
                                    className={
                                        'tabular-nums ' +
                                        (adjustment.kind === 'POTONGAN'
                                            ? 'text-red-700 dark:text-red-400'
                                            : '')
                                    }
                                >
                                    {adjustment.kind === 'POTONGAN'
                                        ? '− '
                                        : '+ '}
                                    {formatRupiah(adjustment.amount)}
                                </span>
                                {!locked && (
                                    <Form
                                        action={`${baseUrl}/${adjustment.id}`}
                                        method="delete"
                                        errorBag={`adj-del-${adjustment.id}`}
                                        options={{ preserveScroll: true }}
                                    >
                                        {({ processing }) => (
                                            <Button
                                                type="submit"
                                                variant="ghost"
                                                size="icon"
                                                disabled={processing}
                                                aria-label={`Hapus penyesuaian ${adjustment.note}`}
                                            >
                                                <Trash2 />
                                            </Button>
                                        )}
                                    </Form>
                                )}
                            </span>
                        </li>
                    ))}
                </ul>
            )}

            {!locked && (
                <Form
                    action={baseUrl}
                    method="post"
                    errorBag={`adj-${row.employee_id}`}
                    resetOnSuccess
                    options={{ preserveScroll: true }}
                    className="grid gap-2 sm:grid-cols-[8.5rem_9rem_minmax(0,1fr)_auto] sm:items-start"
                >
                    {({ processing, errors }) => (
                        <>
                            <input
                                type="hidden"
                                name="employee_id"
                                value={row.employee_id}
                            />
                            <select
                                name="kind"
                                className={selectClass}
                                defaultValue="PENDAPATAN"
                                aria-label="Jenis penyesuaian"
                            >
                                <option value="PENDAPATAN">Tambahan</option>
                                <option value="POTONGAN">Potongan</option>
                            </select>
                            <div className="grid gap-1">
                                <Input
                                    name="amount"
                                    inputMode="numeric"
                                    placeholder="Nominal"
                                    aria-label="Nominal"
                                    maxLength={20}
                                    required
                                />
                                <InputError message={errors.amount} />
                            </div>
                            <div className="grid gap-1">
                                <Input
                                    name="note"
                                    placeholder="Keterangan, mis. Lembur kegiatan"
                                    aria-label="Keterangan"
                                    maxLength={200}
                                    required
                                />
                                <InputError
                                    message={errors.note ?? errors.form}
                                />
                            </div>
                            <Button
                                type="submit"
                                size="sm"
                                disabled={processing}
                                className="h-9"
                            >
                                Tambah
                            </Button>
                        </>
                    )}
                </Form>
            )}
        </div>
    );
}

function Total({ label, text }: { label: string; text: string }) {
    return (
        <div>
            <p className="text-xs text-muted-foreground">{label}</p>
            <p className="font-medium tabular-nums">{text}</p>
        </div>
    );
}
