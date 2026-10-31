import { Form, Head, router } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { StudentHit } from '@/components/finance/student-search';
import { StudentSearch } from '@/components/finance/student-search';
import { FormDialog } from '@/components/form-dialog';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Option = { value: string; label: string };

type DiscountRow = {
    id: string;
    enrollment_id: string;
    name: string;
    nisn: string | null;
    student_number: string | null;
    class: string | null;
    active: boolean;
    fee_type: string;
    kind: 'PERSEN' | 'NOMINAL';
    value: number;
    label: string;
    reason: string;
};

type Props = {
    organizations: Option[];
    organizationId: string | null;
    years: Option[];
    yearId: string | null;
    discounts: DiscountRow[];
    canManage: boolean;
    feeTypes: Option[];
    reasonMax: number;
};

const selectClass =
    'h-9 w-full min-w-0 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';

/**
 * Potongan/beasiswa siswa (PRD-003 §4, FN-1b): per siswa × jenis biaya × tahun ajaran, persen atau
 * nominal, dengan alasan. Berlaku untuk tagihan yang dibuat sesudahnya. Server tetap memeriksa ulang.
 */
export default function Discounts({
    organizations,
    organizationId,
    years,
    yearId,
    discounts,
    canManage,
    feeTypes,
    reasonMax,
}: Props) {
    const [adding, setAdding] = useState(false);

    const visit = (params: Record<string, string>) =>
        router.get('/keuangan/potongan', params, {
            preserveScroll: true,
            preserveState: false,
        });

    return (
        <>
            <Head title="Potongan siswa" />

            <div className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-4 p-4">
                <Heading
                    title="Potongan siswa"
                    description="Potongan atau beasiswa per siswa untuk satu jenis biaya dalam satu tahun ajaran. Berlaku untuk tagihan yang dibuat sesudahnya; tagihan yang sudah ada tidak berubah."
                />

                <div className="grid gap-3 sm:grid-cols-2">
                    <div className="grid gap-1.5">
                        <Label htmlFor="disc-organization">Lembaga</Label>
                        <select
                            id="disc-organization"
                            className={selectClass}
                            value={organizationId ?? ''}
                            onChange={(event) =>
                                visit({ lembaga: event.target.value })
                            }
                        >
                            {organizations.map((organization) => (
                                <option
                                    key={organization.value}
                                    value={organization.value}
                                >
                                    {organization.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="disc-year">Tahun ajaran</Label>
                        <select
                            id="disc-year"
                            className={selectClass}
                            value={yearId ?? ''}
                            disabled={years.length === 0}
                            onChange={(event) =>
                                visit({
                                    lembaga: organizationId ?? '',
                                    tahun: event.target.value,
                                })
                            }
                        >
                            {years.map((year) => (
                                <option key={year.value} value={year.value}>
                                    {year.label}
                                </option>
                            ))}
                        </select>
                    </div>
                </div>

                {yearId === null ? (
                    <Empty>
                        Kalender akademik lembaga ini belum punya tahun ajaran.
                    </Empty>
                ) : (
                    <>
                        {canManage &&
                            organizationId !== null &&
                            (feeTypes.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    Belum ada jenis biaya aktif. Minta Admin
                                    Keuangan menambahkannya di Pengaturan
                                    keuangan.
                                </p>
                            ) : (
                                <>
                                    <Button
                                        type="button"
                                        onClick={() => setAdding(true)}
                                        className="w-full sm:w-auto sm:self-start"
                                    >
                                        <Plus />
                                        Tambah potongan
                                    </Button>
                                    <FormDialog
                                        open={adding}
                                        onOpenChange={setAdding}
                                        title="Tambah / ubah potongan"
                                        description="Siswa yang sudah punya potongan untuk jenis biaya yang sama akan diubah potongannya."
                                        wide
                                    >
                                        <AddForm
                                            organizationId={organizationId}
                                            yearId={yearId}
                                            feeTypes={feeTypes}
                                            reasonMax={reasonMax}
                                            onDone={() => setAdding(false)}
                                        />
                                    </FormDialog>
                                </>
                            ))}

                        {discounts.length === 0 ? (
                            <Empty>
                                Belum ada potongan di lembaga & tahun ajaran
                                ini.
                            </Empty>
                        ) : (
                            <ul className="divide-y rounded-xl border">
                                {discounts.map((discount) => (
                                    <DiscountItem
                                        key={discount.id}
                                        discount={discount}
                                        canManage={canManage}
                                    />
                                ))}
                            </ul>
                        )}
                    </>
                )}
            </div>
        </>
    );
}

function DiscountItem({
    discount,
    canManage,
}: {
    discount: DiscountRow;
    canManage: boolean;
}) {
    const [confirming, setConfirming] = useState(false);
    const identity = [
        discount.class,
        discount.student_number ? `NIS ${discount.student_number}` : null,
        discount.nisn ? `NISN ${discount.nisn}` : null,
    ]
        .filter(Boolean)
        .join(' · ');

    return (
        <li className="space-y-2 p-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0 space-y-0.5">
                    <p className="font-medium break-words">
                        {discount.name}
                        {!discount.active && (
                            <Badge variant="outline" className="ml-2">
                                Tidak aktif di lembaga
                            </Badge>
                        )}
                    </p>
                    {identity && (
                        <p className="text-xs text-muted-foreground">
                            {identity}
                        </p>
                    )}
                    <p className="text-sm break-words">
                        {discount.fee_type}:{' '}
                        <span className="font-medium">{discount.label}</span>{' '}
                        <span className="text-muted-foreground">
                            — {discount.reason}
                        </span>
                    </p>
                </div>
                {canManage && !confirming && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="text-destructive"
                        onClick={() => setConfirming(true)}
                    >
                        <Trash2 />
                        Hapus
                    </Button>
                )}
            </div>

            {confirming && (
                <Form
                    action={`/keuangan/potongan/${discount.id}`}
                    method="delete"
                    errorBag={`remove-${discount.id}`}
                    options={{ preserveScroll: true }}
                    className="space-y-2 rounded-md border border-destructive/40 p-3"
                >
                    {({ processing, errors }) => (
                        <>
                            <p className="text-sm">
                                Hapus potongan {discount.fee_type} untuk{' '}
                                {discount.name}? Tagihan yang sudah dibuat tidak
                                berubah.
                            </p>
                            <InputError message={errors.form} />
                            <div className="grid grid-cols-[repeat(auto-fit,minmax(8rem,1fr))] gap-2 sm:flex">
                                <Button
                                    type="submit"
                                    size="sm"
                                    variant="destructive"
                                    disabled={processing}
                                >
                                    Ya, hapus
                                </Button>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={() => setConfirming(false)}
                                >
                                    Batal
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            )}
        </li>
    );
}

function AddForm({
    organizationId,
    yearId,
    feeTypes,
    reasonMax,
    onDone,
}: {
    organizationId: string;
    yearId: string;
    feeTypes: Option[];
    reasonMax: number;
    onDone: () => void;
}) {
    const [student, setStudent] = useState<StudentHit | null>(null);
    const [kind, setKind] = useState<'PERSEN' | 'NOMINAL'>('PERSEN');

    return (
        <Form
            action="/keuangan/potongan"
            method="post"
            errorBag="addDiscount"
            options={{ preserveScroll: true }}
            onSuccess={onDone}
            className="space-y-4"
        >
            {({ processing, errors }) => (
                <>
                    <input
                        type="hidden"
                        name="organization_id"
                        value={organizationId}
                    />
                    <input
                        type="hidden"
                        name="academic_year_id"
                        value={yearId}
                    />
                    <input
                        type="hidden"
                        name="enrollment_id"
                        value={student?.enrollment_id ?? ''}
                    />

                    {student ? (
                        <div className="flex flex-wrap items-center justify-between gap-2 rounded-md border bg-background p-3 text-sm">
                            <span className="min-w-0 break-words">
                                <span className="font-medium">
                                    {student.name}
                                </span>{' '}
                                <span className="text-muted-foreground">
                                    {[
                                        student.class,
                                        student.student_number
                                            ? `NIS ${student.student_number}`
                                            : null,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                                </span>
                            </span>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() => setStudent(null)}
                            >
                                Ganti siswa
                            </Button>
                        </div>
                    ) : (
                        <StudentSearch
                            organizationId={organizationId}
                            onSelect={setStudent}
                        />
                    )}
                    <InputError message={errors.enrollment_id} />

                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="grid gap-1.5">
                            <Label htmlFor="disc-fee-type">Jenis biaya</Label>
                            <select
                                id="disc-fee-type"
                                name="fee_type_id"
                                className={selectClass}
                                required
                            >
                                {feeTypes.map((type) => (
                                    <option key={type.value} value={type.value}>
                                        {type.label}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.fee_type_id} />
                        </div>

                        <div className="grid gap-1.5">
                            <Label htmlFor="disc-value">
                                Besar potongan{' '}
                                {kind === 'PERSEN' ? '(%)' : '(Rp per tagihan)'}
                            </Label>
                            <div className="grid grid-cols-[auto_minmax(0,1fr)] gap-2">
                                <select
                                    name="kind"
                                    aria-label="Jenis potongan"
                                    className={selectClass + ' w-auto'}
                                    value={kind}
                                    onChange={(event) =>
                                        setKind(
                                            event.target.value as
                                                | 'PERSEN'
                                                | 'NOMINAL',
                                        )
                                    }
                                >
                                    <option value="PERSEN">Persen</option>
                                    <option value="NOMINAL">Nominal</option>
                                </select>
                                <Input
                                    id="disc-value"
                                    name="value"
                                    inputMode="numeric"
                                    placeholder={
                                        kind === 'PERSEN'
                                            ? 'mis. 50'
                                            : 'mis. 50.000'
                                    }
                                    maxLength={20}
                                    required
                                />
                            </div>
                            <InputError message={errors.value ?? errors.kind} />
                        </div>
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="disc-reason">Alasan</Label>
                        <Input
                            id="disc-reason"
                            name="reason"
                            maxLength={reasonMax}
                            placeholder="mis. Yatim, anak guru, beasiswa prestasi"
                            required
                        />
                        <InputError message={errors.reason} />
                    </div>

                    <InputError
                        message={
                            errors.form ??
                            errors.academic_year_id ??
                            errors.organization_id
                        }
                    />

                    <div className="grid grid-cols-[repeat(auto-fit,minmax(9rem,1fr))] gap-2 sm:flex">
                        <Button
                            type="submit"
                            disabled={processing || student === null}
                        >
                            {processing ? 'Menyimpan…' : 'Simpan potongan'}
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

function Empty({ children }: { children: React.ReactNode }) {
    return (
        <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
            {children}
        </p>
    );
}
