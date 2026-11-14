import { Form } from '@inertiajs/react';
import { Lock, Pencil } from 'lucide-react';
import { useState } from 'react';
import { FormDialog } from '@/components/form-dialog';
import InputError from '@/components/input-error';
import { selectClass } from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Option = { value: string; label: string };

export type PtkData = {
    values: {
        /** Null bila belum diisi ATAU pengguna tidak boleh melihat data pribadi (lihat has_mother_name). */
        mother_name: string | null;
        has_mother_name: boolean;
        sd_graduation_year: number | null;
        ptk_function: string | null;
        ptk_function_label: string | null;
        civil_servant: string | null;
        civil_servant_label: string | null;
        certified: boolean | null;
        certification_year: number | null;
        educator_certificate_number: string | null;
    };
    withPersonal: boolean;
    manage: {
        functions: Option[];
        civilServants: Option[];
        yearMin: number;
        yearMax: number;
    } | null;
};

/**
 * Tab "Data PTK" di detail pegawai (PRD-001 §21.1, HR-10a): isian untuk rekap PTK Simpatika.
 * Nama ibu kandung terenkripsi dan hanya tampil bagi yang boleh melihat data pribadi.
 */
export function PtkSection({
    employeeId,
    data,
}: {
    employeeId: string;
    data: PtkData;
}) {
    const [editing, setEditing] = useState(false);
    const { values } = data;

    const motherName = data.withPersonal
        ? values.mother_name
        : values.has_mother_name
          ? 'Tersimpan (data pribadi)'
          : null;
    const certification =
        values.certified === null
            ? null
            : values.certified
              ? `Sudah, tahun ${values.certification_year}`
              : 'Belum';

    const rows: { label: string; value: string | null; mono?: boolean }[] = [
        { label: 'Nama ibu kandung', value: motherName },
        {
            label: 'Tahun lulus SD',
            value: values.sd_graduation_year?.toString() ?? null,
        },
        { label: 'Fungsi PTK', value: values.ptk_function_label },
        { label: 'Status PNS', value: values.civil_servant_label },
        { label: 'Sertifikasi guru', value: certification },
        {
            label: 'No. sertifikat pendidik',
            value: values.educator_certificate_number,
            mono: true,
        },
    ];

    return (
        <section className="space-y-3 rounded-xl border p-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="space-y-0.5">
                    <h2 className="text-base font-medium">Data PTK</h2>
                    <p className="text-sm text-muted-foreground">
                        Isian tambahan untuk rekap PTK Simpatika. Data lain
                        (NIK, NUPTK, tanggal lahir, pendidikan) diambil dari tab
                        Data diri.
                    </p>
                </div>
                {data.manage && (
                    <Button
                        type="button"
                        variant="outline"
                        className="w-full sm:w-auto"
                        onClick={() => setEditing(true)}
                    >
                        <Pencil />
                        Ubah data PTK
                    </Button>
                )}
            </div>

            <dl className="grid grid-cols-[repeat(auto-fit,minmax(12rem,1fr))] gap-3">
                {rows.map((row) => (
                    <div key={row.label} className="min-w-0 space-y-0.5">
                        <dt className="text-xs text-muted-foreground">
                            {row.label}
                        </dt>
                        <dd
                            className={
                                'text-sm break-words ' +
                                (row.mono && row.value ? 'font-mono' : '')
                            }
                        >
                            {row.value ?? (
                                <span className="text-muted-foreground">
                                    Belum diisi
                                </span>
                            )}
                        </dd>
                    </div>
                ))}
            </dl>

            {data.manage && (
                <FormDialog
                    open={editing}
                    onOpenChange={setEditing}
                    title="Ubah data PTK"
                    description="Semua isian boleh dikosongkan; yang kosong tampil di kelengkapan rekap PTK."
                >
                    <PtkForm
                        employeeId={employeeId}
                        data={data}
                        manage={data.manage}
                        onDone={() => setEditing(false)}
                    />
                </FormDialog>
            )}
        </section>
    );
}

function PtkForm({
    employeeId,
    data,
    manage,
    onDone,
}: {
    employeeId: string;
    data: PtkData;
    manage: NonNullable<PtkData['manage']>;
    onDone: () => void;
}) {
    const { values } = data;
    const [certified, setCertified] = useState(
        values.certified === null ? '' : values.certified ? 'SUDAH' : 'BELUM',
    );

    return (
        <Form
            action={`/pegawai/${employeeId}/ptk`}
            method="patch"
            errorBag="ptk"
            options={{ preserveScroll: true }}
            onSuccess={onDone}
            className="space-y-3"
        >
            {({ processing, errors }) => (
                <>
                    {data.withPersonal ? (
                        <div className="grid gap-1.5">
                            <Label htmlFor="ptk-mother">Nama ibu kandung</Label>
                            <Input
                                id="ptk-mother"
                                name="mother_name"
                                maxLength={100}
                                autoComplete="off"
                                defaultValue={values.mother_name ?? ''}
                            />
                            <p className="flex items-center gap-1 text-xs text-muted-foreground">
                                <Lock className="size-3" />
                                Disimpan terenkripsi; hanya terlihat oleh yang
                                berhak melihat data pribadi.
                            </p>
                            <InputError message={errors.mother_name} />
                        </div>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            Nama ibu kandung hanya bisa diubah oleh yang berhak
                            melihat data pribadi pegawai ini.
                        </p>
                    )}

                    <div className="grid grid-cols-[repeat(auto-fit,minmax(10rem,1fr))] gap-3">
                        <div className="grid content-start gap-1.5">
                            <Label htmlFor="ptk-sd">Tahun lulus SD</Label>
                            <Input
                                id="ptk-sd"
                                name="sd_graduation_year"
                                inputMode="numeric"
                                maxLength={4}
                                placeholder={`mis. ${manage.yearMax - 30}`}
                                defaultValue={values.sd_graduation_year ?? ''}
                            />
                            <InputError message={errors.sd_graduation_year} />
                        </div>
                        <div className="grid content-start gap-1.5">
                            <Label htmlFor="ptk-civil">Status PNS</Label>
                            <select
                                id="ptk-civil"
                                name="civil_servant"
                                className={selectClass}
                                defaultValue={values.civil_servant ?? ''}
                            >
                                <option value="">(belum diisi)</option>
                                {manage.civilServants.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.civil_servant} />
                        </div>
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="ptk-function">Fungsi PTK</Label>
                        <select
                            id="ptk-function"
                            name="ptk_function"
                            className={selectClass}
                            defaultValue={values.ptk_function ?? ''}
                        >
                            <option value="">(belum diisi)</option>
                            {manage.functions.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.ptk_function} />
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="ptk-certified">Sertifikasi guru</Label>
                        <select
                            id="ptk-certified"
                            name="certified"
                            className={selectClass}
                            value={certified}
                            onChange={(event) =>
                                setCertified(event.target.value)
                            }
                        >
                            <option value="">(belum diisi)</option>
                            <option value="BELUM">Belum</option>
                            <option value="SUDAH">Sudah</option>
                        </select>
                        <InputError message={errors.certified} />
                    </div>

                    {certified === 'SUDAH' && (
                        <div className="grid grid-cols-[repeat(auto-fit,minmax(10rem,1fr))] gap-3">
                            <div className="grid content-start gap-1.5">
                                <Label htmlFor="ptk-cert-year">
                                    Tahun sertifikasi
                                </Label>
                                <Input
                                    id="ptk-cert-year"
                                    name="certification_year"
                                    inputMode="numeric"
                                    maxLength={4}
                                    required
                                    defaultValue={
                                        values.certification_year ?? ''
                                    }
                                />
                                <InputError
                                    message={errors.certification_year}
                                />
                            </div>
                            <div className="grid content-start gap-1.5">
                                <Label htmlFor="ptk-cert-number">
                                    No. sertifikat pendidik (opsional)
                                </Label>
                                <Input
                                    id="ptk-cert-number"
                                    name="educator_certificate_number"
                                    maxLength={50}
                                    defaultValue={
                                        values.educator_certificate_number ?? ''
                                    }
                                />
                                <InputError
                                    message={errors.educator_certificate_number}
                                />
                            </div>
                        </div>
                    )}

                    <InputError message={errors.form} />
                    <Button type="submit" disabled={processing}>
                        Simpan data PTK
                    </Button>
                </>
            )}
        </Form>
    );
}
