import { Form, Head, Link, router } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import type { PaySummary } from '@/components/hr/pay-format';
import { formatNumber, formatRupiah } from '@/components/hr/pay-format';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type TeachingLine = {
    organization_id: string;
    organization: string;
    mode: 'JAM_MATI' | 'JAM_HIDUP';
    mode_label: string;
    minutes_per_jp: number;
    sessions: number;
    jp: number;
    rate: number;
    amount: number;
    irregular: number;
};

type Preview = {
    month: string;
    maxMonth: string;
    label: string;
    until: string;
    teaching: { eligible: boolean; lines: TeachingLine[]; total: number };
    attendance: {
        present_days: number;
        absent: number;
        late: number;
        early: number;
        empty: number;
        rates: { absent: number; late: number; early: number };
        deduction: number;
    } | null;
    present_day_pay: number;
    earning: number;
    deduction: number;
};

type Props = {
    employee: {
        id: string;
        name: string;
        employee_number: string | null;
        status: string;
    };
    pay: PaySummary;
    preview: Preview;
};

/**
 * Setup gaji satu pegawai (PRD-001 §16.1, HR-5a-2): rincian dari tarif jabatan (hanya baca) dan
 * nominal komponen per pegawai. Hanya Admin SDM / Admin Yayasan.
 */
export default function PayrollEmployee({ employee, pay, preview }: Props) {
    const active = pay.rows.filter((row) => row.is_active);
    const inactive = pay.rows.filter(
        (row) => !row.is_active && row.amount !== null,
    );

    return (
        <>
            <Head title={`Gaji ${employee.name}`} />

            <div className="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-4 p-4">
                <Button
                    variant="ghost"
                    size="sm"
                    asChild
                    className="self-start"
                >
                    <Link href="/pegawai/gaji">
                        <ArrowLeft />
                        Gaji pegawai
                    </Link>
                </Button>

                <Heading
                    title={employee.name}
                    description={
                        [
                            employee.employee_number,
                            employee.status === 'BERHENTI'
                                ? 'Sudah berhenti'
                                : null,
                        ]
                            .filter(Boolean)
                            .join(' · ') || 'Setup gaji pegawai'
                    }
                />

                <section
                    aria-label="Dari jabatan"
                    className="space-y-3 rounded-xl border p-4"
                >
                    <div className="space-y-1">
                        <h2 className="text-base font-medium">Dari jabatan</h2>
                        <p className="text-sm text-muted-foreground">
                            Otomatis dari tarif jabatan di Pengaturan gaji,
                            untuk setiap jabatan yang sedang dipegang. Ubah
                            tarifnya di{' '}
                            <Link
                                href="/pegawai/gaji/pengaturan"
                                className="underline underline-offset-4"
                            >
                                Pengaturan gaji
                            </Link>
                            ; ubah jabatannya di detail pegawai.
                        </p>
                    </div>

                    {pay.placements.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Pegawai ini tidak punya penempatan berjalan.
                        </p>
                    ) : (
                        <p className="text-sm">
                            Jabatan:{' '}
                            {pay.placements
                                .map(
                                    (placement) =>
                                        `${placement.position} — ${placement.organization}${placement.is_primary ? ' (utama)' : ''}`,
                                )
                                .join('; ')}
                        </p>
                    )}

                    {pay.positions.length === 0 ? (
                        <p className="rounded-lg border border-dashed p-3 text-center text-sm text-muted-foreground">
                            Belum ada tarif untuk jabatan pegawai ini.
                        </p>
                    ) : (
                        <ul className="divide-y rounded-lg border">
                            {pay.positions.map((line, index) => (
                                <li
                                    key={`${line.component_id}-${index}`}
                                    className="flex flex-wrap items-center justify-between gap-2 p-3 text-sm"
                                >
                                    <span className="min-w-0 break-words">
                                        {line.component}
                                        <span className="block text-xs text-muted-foreground">
                                            {line.position} —{' '}
                                            {line.organization}
                                            {line.scope === 'SEMUA'
                                                ? ' (tarif semua lembaga)'
                                                : ' (tarif lembaga ini)'}
                                        </span>
                                    </span>
                                    <span
                                        className={
                                            'font-medium tabular-nums ' +
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
                </section>

                <Form
                    action={`/pegawai/gaji/${employee.id}`}
                    method="patch"
                    errorBag="pay"
                    options={{ preserveScroll: true }}
                    className="space-y-4 rounded-xl border p-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="space-y-1">
                                <h2 className="text-base font-medium">
                                    Komponen per pegawai
                                </h2>
                                <p className="text-sm text-muted-foreground">
                                    Rupiah bulat, mis. 2.500.000. Kosongkan bila
                                    komponen tidak berlaku untuk pegawai ini.
                                </p>
                            </div>
                            <InputError
                                message={errors.form ?? errors.amounts}
                            />

                            {active.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    Belum ada komponen per pegawai yang aktif.
                                </p>
                            ) : (
                                <div className="grid gap-4 sm:grid-cols-2">
                                    {active.map((row) => {
                                        const id = `pay-${row.component_id}`;

                                        return (
                                            <div
                                                key={row.component_id}
                                                className="grid content-start gap-1.5"
                                            >
                                                <label
                                                    htmlFor={id}
                                                    className="flex flex-wrap items-center gap-1.5 text-sm font-medium"
                                                >
                                                    {row.name}
                                                    <Badge
                                                        variant={
                                                            row.kind ===
                                                            'POTONGAN'
                                                                ? 'outline'
                                                                : 'secondary'
                                                        }
                                                    >
                                                        {row.kind_label}
                                                    </Badge>
                                                    {row.basis ===
                                                        'PER_HARI_HADIR' && (
                                                        <Badge variant="outline">
                                                            per hari hadir
                                                        </Badge>
                                                    )}
                                                </label>
                                                <Input
                                                    id={id}
                                                    name={`amounts[${row.component_id}]`}
                                                    inputMode="numeric"
                                                    defaultValue={
                                                        row.amount === null
                                                            ? ''
                                                            : formatNumber(
                                                                  row.amount,
                                                              )
                                                    }
                                                    placeholder="Tidak berlaku"
                                                    maxLength={20}
                                                />
                                                <InputError
                                                    message={
                                                        errors[
                                                            `amounts.${row.component_id}`
                                                        ]
                                                    }
                                                />
                                            </div>
                                        );
                                    })}
                                </div>
                            )}

                            {inactive.length > 0 && (
                                <p className="text-xs text-muted-foreground">
                                    Komponen nonaktif (tidak ikut rekap):{' '}
                                    {inactive
                                        .map(
                                            (row) =>
                                                `${row.name} ${formatRupiah(row.amount ?? 0)}`,
                                        )
                                        .join('; ')}
                                </p>
                            )}

                            {active.length > 0 && (
                                <Button type="submit" disabled={processing}>
                                    {processing ? 'Menyimpan…' : 'Simpan'}
                                </Button>
                            )}
                        </>
                    )}
                </Form>

                <PreviewSection employeeId={employee.id} preview={preview} />

                <section
                    aria-label="Ringkasan"
                    className="grid grid-cols-[repeat(auto-fit,minmax(12rem,1fr))] gap-3 rounded-xl bg-muted/40 p-4 text-sm"
                >
                    <Total
                        label="Pendapatan tetap / bulan"
                        value={pay.monthly_earning}
                    />
                    <Total
                        label="Potongan tetap / bulan"
                        value={pay.monthly_deduction}
                    />
                    <Total label="Per hari hadir" value={pay.per_present_day} />
                    <p className="text-xs text-muted-foreground sm:col-span-full">
                        Honor JP, potongan presensi, dan uang per hari hadir ×
                        jumlah hari dihitung di rekap gaji bulanan.
                    </p>
                </section>
            </div>
        </>
    );
}

/**
 * Perkiraan gaji bulan terpilih (HR-5b): honor JP per lembaga, uang per hari hadir, potongan presensi.
 * Angka akhir dikunci di rekap gaji bulanan (HR-5c).
 */
function PreviewSection({
    employeeId,
    preview,
}: {
    employeeId: string;
    preview: Preview;
}) {
    const attendance = preview.attendance;

    return (
        <section
            aria-label="Perkiraan bulan"
            className="space-y-3 rounded-xl border p-4"
        >
            <div className="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                <div className="space-y-1">
                    <h2 className="text-base font-medium">
                        Perkiraan {preview.label}
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        {preview.month === preview.maxMonth
                            ? `Dihitung sampai ${preview.until}. `
                            : ''}
                        Angka akhir dikunci di rekap gaji bulanan.
                    </p>
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor="preview-month">Bulan</Label>
                    <Input
                        id="preview-month"
                        type="month"
                        value={preview.month}
                        max={preview.maxMonth}
                        onChange={(event) =>
                            event.target.value &&
                            router.get(
                                `/pegawai/gaji/${employeeId}`,
                                { bulan: event.target.value },
                                { preserveScroll: true, replace: true },
                            )
                        }
                    />
                </div>
            </div>

            <div className="space-y-2">
                <p className="text-sm font-medium">Honor mengajar</p>
                {preview.teaching.lines.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Tidak ada jadwal/pertemuan mengajar.
                    </p>
                ) : (
                    <>
                        {!preview.teaching.eligible && (
                            <p className="rounded-md border border-amber-400/60 bg-amber-50 p-2 text-xs text-amber-900 dark:bg-amber-950/30 dark:text-amber-100">
                                Pegawai ini mengajar, tetapi jenis pegawai &
                                status kepegawaiannya tidak dicentang “Menerima
                                honor per JP”, jadi honornya Rp 0.
                            </p>
                        )}
                        <ul className="divide-y rounded-lg border">
                            {preview.teaching.lines.map((line) => (
                                <li
                                    key={line.organization_id}
                                    className="flex flex-wrap items-center justify-between gap-2 p-3 text-sm"
                                >
                                    <span className="min-w-0 break-words">
                                        {line.organization}
                                        <span className="block text-xs text-muted-foreground">
                                            {line.mode_label}:{' '}
                                            {line.mode === 'JAM_MATI'
                                                ? `${line.sessions} jadwal/minggu`
                                                : `${line.sessions} pertemuan`}{' '}
                                            = {line.jp} JP ×{' '}
                                            {formatRupiah(line.rate)} (
                                            {line.minutes_per_jp} mnt/JP)
                                            {line.irregular > 0 &&
                                                ` · ⚠ ${line.irregular} tidak pas kelipatan JP (dibulatkan ke bawah)`}
                                        </span>
                                    </span>
                                    <span className="font-medium tabular-nums">
                                        {formatRupiah(line.amount)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </>
                )}
            </div>

            <div className="space-y-2">
                <p className="text-sm font-medium">Presensi</p>
                {attendance === null ? (
                    <p className="text-sm text-muted-foreground">
                        Pegawai ini tidak punya penempatan utama.
                    </p>
                ) : (
                    <dl className="grid grid-cols-[repeat(auto-fit,minmax(10rem,1fr))] gap-3 text-sm">
                        <div>
                            <dt className="text-xs text-muted-foreground">
                                Hari hadir (Hadir + Dinas luar)
                            </dt>
                            <dd className="font-medium tabular-nums">
                                {attendance.present_days} hari ·{' '}
                                {formatRupiah(preview.present_day_pay)}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs text-muted-foreground">
                                Alpa / Terlambat / Pulang cepat
                            </dt>
                            <dd className="font-medium tabular-nums">
                                {attendance.absent} / {attendance.late} /{' '}
                                {attendance.early}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs text-muted-foreground">
                                Potongan presensi
                            </dt>
                            <dd className="font-medium text-red-700 tabular-nums dark:text-red-400">
                                − {formatRupiah(attendance.deduction)}
                            </dd>
                        </div>
                        {attendance.empty > 0 && (
                            <p className="text-xs text-amber-700 sm:col-span-full dark:text-amber-300">
                                ⚠ {attendance.empty} hari wajib belum diisi
                                presensinya (tidak dipotong).
                            </p>
                        )}
                    </dl>
                )}
            </div>

            <div className="grid grid-cols-[repeat(auto-fit,minmax(10rem,1fr))] gap-3 rounded-lg bg-muted/40 p-3 text-sm">
                <Total label="Total pendapatan" value={preview.earning} />
                <Total label="Total potongan" value={preview.deduction} />
                <Total
                    label="Perkiraan bersih"
                    value={preview.earning - preview.deduction}
                />
            </div>
        </section>
    );
}

function Total({ label, value }: { label: string; value: number }) {
    return (
        <div>
            <p className="text-xs text-muted-foreground">{label}</p>
            <p className="font-medium tabular-nums">{formatRupiah(value)}</p>
        </div>
    );
}
