import { Form } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

/*
 * Komponen gaji di detail pegawai (PRD-001 §16.1, HR-5a). Hanya tampil bagi Admin SDM / Admin Yayasan
 * (`hr.payroll.manage`); server tetap memeriksa ulang.
 */

export type PayRow = {
    component_id: string;
    code: string;
    name: string;
    kind: 'PENDAPATAN' | 'POTONGAN';
    kind_label: string;
    basis: 'TETAP' | 'PER_HARI_HADIR';
    basis_label: string;
    is_active: boolean;
    amount: number | null;
};

export type PaySummary = {
    rows: PayRow[];
    monthly_earning: number;
    monthly_deduction: number;
    per_present_day: number;
};

const rupiah = new Intl.NumberFormat('id-ID');

export function formatRupiah(value: number): string {
    return `Rp ${rupiah.format(value)}`;
}

export function PaySection({
    employeeId,
    pay,
}: {
    employeeId: string;
    pay: PaySummary;
}) {
    const [editing, setEditing] = useState(false);
    const filled = pay.rows.filter((row) => row.amount !== null);

    return (
        <section
            aria-label="Komponen gaji"
            className="space-y-3 rounded-xl border p-4"
        >
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-base font-medium">Komponen gaji</h2>
                {!editing && pay.rows.some((row) => row.is_active) && (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => setEditing(true)}
                    >
                        <Pencil />
                        Ubah nominal
                    </Button>
                )}
            </div>

            {editing ? (
                <PayForm
                    employeeId={employeeId}
                    rows={pay.rows}
                    onDone={() => setEditing(false)}
                />
            ) : filled.length === 0 ? (
                <p className="rounded-lg border border-dashed p-4 text-center text-sm text-muted-foreground">
                    Belum ada nominal komponen gaji untuk pegawai ini.
                </p>
            ) : (
                <>
                    <dl className="grid grid-cols-[repeat(auto-fit,minmax(12rem,1fr))] gap-3">
                        {filled.map((row) => (
                            <div key={row.component_id} className="min-w-0">
                                <dt className="text-xs text-muted-foreground">
                                    {row.name}
                                    {row.basis === 'PER_HARI_HADIR' &&
                                        ' (per hari hadir)'}
                                    {!row.is_active && ' — nonaktif'}
                                </dt>
                                <dd
                                    className={
                                        'text-sm font-medium tabular-nums ' +
                                        (row.kind === 'POTONGAN'
                                            ? 'text-red-700 dark:text-red-400'
                                            : '')
                                    }
                                >
                                    {row.kind === 'POTONGAN' && '− '}
                                    {formatRupiah(row.amount ?? 0)}
                                </dd>
                            </div>
                        ))}
                    </dl>
                    <div className="grid grid-cols-[repeat(auto-fit,minmax(12rem,1fr))] gap-3 rounded-lg bg-muted/40 p-3 text-sm">
                        <Total
                            label="Pendapatan tetap / bulan"
                            value={pay.monthly_earning}
                        />
                        <Total
                            label="Potongan tetap / bulan"
                            value={pay.monthly_deduction}
                        />
                        <Total
                            label="Per hari hadir"
                            value={pay.per_present_day}
                        />
                    </div>
                    <p className="text-xs text-muted-foreground">
                        Honor JP, potongan presensi, dan jumlah hari hadir
                        dihitung di rekap gaji bulanan.
                    </p>
                </>
            )}
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

function PayForm({
    employeeId,
    rows,
    onDone,
}: {
    employeeId: string;
    rows: PayRow[];
    onDone: () => void;
}) {
    const active = rows.filter((row) => row.is_active);

    return (
        <Form
            action={`/pegawai/${employeeId}/gaji`}
            method="patch"
            errorBag="pay"
            options={{ preserveScroll: true }}
            onSuccess={onDone}
            className="space-y-4 rounded-xl border border-primary/30 bg-muted/20 p-4"
        >
            {({ processing, errors }) => (
                <>
                    <p className="text-sm text-muted-foreground">
                        Rupiah bulat, mis. 2.500.000. Kosongkan bila komponen
                        tidak berlaku untuk pegawai ini.
                    </p>
                    <InputError message={errors.form ?? errors.amounts} />

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
                                                row.kind === 'POTONGAN'
                                                    ? 'outline'
                                                    : 'secondary'
                                            }
                                        >
                                            {row.kind_label}
                                        </Badge>
                                        {row.basis === 'PER_HARI_HADIR' && (
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
                                                : rupiah.format(row.amount)
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

                    <div className="grid grid-cols-[repeat(auto-fit,minmax(9rem,1fr))] gap-2 sm:flex">
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Menyimpan…' : 'Simpan nominal'}
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onDone}
                        >
                            Batal
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}
