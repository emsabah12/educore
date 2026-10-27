import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import type { PaySummary } from '@/components/hr/pay-format';
import { formatNumber, formatRupiah } from '@/components/hr/pay-format';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Props = {
    employee: {
        id: string;
        name: string;
        employee_number: string | null;
        status: string;
    };
    pay: PaySummary;
};

/**
 * Setup gaji satu pegawai (PRD-001 §16.1, HR-5a-2): rincian dari tarif jabatan (hanya baca) dan
 * nominal komponen per pegawai. Hanya Admin SDM / Admin Yayasan.
 */
export default function PayrollEmployee({ employee, pay }: Props) {
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

function Total({ label, value }: { label: string; value: number }) {
    return (
        <div>
            <p className="text-xs text-muted-foreground">{label}</p>
            <p className="font-medium tabular-nums">{formatRupiah(value)}</p>
        </div>
    );
}
