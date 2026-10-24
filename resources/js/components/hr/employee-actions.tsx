import { Form } from '@inertiajs/react';
import { useState } from 'react';
import { NikField } from '@/components/nik-field';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/*
 * Form aksi di detail pegawai (HR-1d, PRD-001 §6). Semua aturan & hak akses tetap dicek server;
 * form di sini hanya membantu (batas tanggal, pilihan yang aktif).
 */

export type Option = { value: string; label: string };

export type ManageData = {
    today: string;
    values: Record<string, string | null>;
    options: {
        employmentTypes: Option[];
        classifications: Option[];
        positions: Option[];
        organizations: Option[];
        educationLevels: Option[];
        genders: Option[];
        endReasons: Option[];
        /** Jenis dokumen (HR-2a), termasuk yang nonaktif. */
        documentTypes: DocumentTypeOption[];
        documentMaxMb: number;
    };
};

export type DocumentTypeOption = Option & {
    has_expiry: boolean;
    is_active: boolean;
};

export type PlacementRef = {
    id: string;
    organization: string;
    position: string;
    is_primary: boolean;
    start_date: string;
};

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:opacity-50 dark:bg-input/30';

const textareaClass =
    'min-h-20 w-full rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm dark:bg-input/30';

const gridClass = 'grid gap-4 sm:grid-cols-2';

type ActionProps = {
    employeeId: string;
    manage: ManageData;
    onDone: () => void;
};

// ── Ubah profil ──────────────────────────────────────────────────────────────

export function ProfileForm({ employeeId, manage, onDone }: ActionProps) {
    const v = manage.values;
    // Untuk peringatan NIK vs tanggal lahir/jenis kelamin (tidak menghalangi simpan).
    const [birthDate, setBirthDate] = useState(
        v.birth_date?.slice(0, 10) ?? '',
    );
    const [gender, setGender] = useState(v.gender ?? '');

    return (
        <ActionForm
            title="Ubah profil"
            action={`/pegawai/${employeeId}`}
            method="patch"
            errorBag="profile"
            submitLabel="Simpan profil"
            onDone={onDone}
            note="Kolom yang dikosongkan berarti datanya dihapus (kecuali NIK yang sudah tercatat). Nama ikut berubah di akun login bila pegawai punya akun."
        >
            {(errors) => (
                <>
                    <Field
                        id="p-name"
                        label="Nama lengkap"
                        required
                        error={errors.name}
                    >
                        <Input
                            id="p-name"
                            name="name"
                            defaultValue={v.name ?? ''}
                            maxLength={200}
                            required
                        />
                    </Field>
                    <Field
                        id="p-gender"
                        label="Jenis kelamin"
                        required
                        error={errors.gender}
                    >
                        <SelectOptions
                            id="p-gender"
                            name="gender"
                            options={manage.options.genders}
                            placeholder="Pilih"
                            defaultValue={v.gender ?? ''}
                            onChange={setGender}
                            required
                        />
                    </Field>
                    <NikField
                        id="p-nik"
                        required={Boolean(v.nik)}
                        defaultValue={v.nik ?? ''}
                        birthDate={birthDate}
                        gender={gender}
                        error={errors.nik}
                        hint={
                            v.nik
                                ? undefined
                                : 'Pegawai lama boleh belum punya NIK; lengkapi bila datanya sudah ada.'
                        }
                    />
                    <Field
                        id="p-birth-place"
                        label="Tempat lahir"
                        error={errors.birth_place}
                    >
                        <Input
                            id="p-birth-place"
                            name="birth_place"
                            defaultValue={v.birth_place ?? ''}
                            maxLength={100}
                        />
                    </Field>
                    <Field
                        id="p-birth-date"
                        label="Tanggal lahir"
                        error={errors.birth_date}
                    >
                        <Input
                            id="p-birth-date"
                            name="birth_date"
                            type="date"
                            max={manage.today}
                            defaultValue={v.birth_date?.slice(0, 10) ?? ''}
                            onChange={(event) =>
                                setBirthDate(event.target.value)
                            }
                        />
                    </Field>
                    <Field id="p-phone" label="Nomor HP" error={errors.phone}>
                        <Input
                            id="p-phone"
                            name="phone"
                            type="tel"
                            inputMode="tel"
                            defaultValue={v.phone ?? ''}
                            maxLength={25}
                        />
                    </Field>
                    <Field
                        id="p-number"
                        label="Nomor induk yayasan"
                        error={errors.employee_number}
                    >
                        <Input
                            id="p-number"
                            name="employee_number"
                            defaultValue={v.employee_number ?? ''}
                            maxLength={30}
                            className="uppercase"
                        />
                    </Field>
                    <Field id="p-nuptk" label="NUPTK" error={errors.nuptk}>
                        <Input
                            id="p-nuptk"
                            name="nuptk"
                            inputMode="numeric"
                            defaultValue={v.nuptk ?? ''}
                            maxLength={19}
                        />
                    </Field>
                    <Field
                        id="p-education"
                        label="Pendidikan terakhir"
                        error={errors.education_level}
                    >
                        <SelectOptions
                            id="p-education"
                            name="education_level"
                            options={manage.options.educationLevels}
                            placeholder="Tidak diisi"
                            defaultValue={v.education_level ?? ''}
                        />
                    </Field>
                    <Field
                        id="p-major"
                        label="Program studi/jurusan"
                        error={errors.education_major}
                    >
                        <Input
                            id="p-major"
                            name="education_major"
                            defaultValue={v.education_major ?? ''}
                            maxLength={150}
                        />
                    </Field>
                    <Field
                        id="p-address"
                        label="Alamat"
                        error={errors.address}
                        className="sm:col-span-2"
                    >
                        <textarea
                            id="p-address"
                            name="address"
                            defaultValue={v.address ?? ''}
                            maxLength={500}
                            rows={2}
                            className={textareaClass}
                        />
                    </Field>
                </>
            )}
        </ActionForm>
    );
}

// ── Ganti status kepegawaian ─────────────────────────────────────────────────

export function StatusForm({ employeeId, manage, onDone }: ActionProps) {
    return (
        <ActionForm
            title="Ganti jenis/status kepegawaian"
            action={`/pegawai/${employeeId}/status`}
            errorBag="status"
            submitLabel="Simpan perubahan"
            onDone={onDone}
            note="Periode sekarang ditutup sehari sebelum tanggal berlaku, lalu periode baru dibuka. Penempatan tidak berubah."
        >
            {(errors) => (
                <>
                    <Field
                        id="s-type"
                        label="Jenis pegawai baru"
                        required
                        error={errors.employment_type_id}
                    >
                        <SelectOptions
                            id="s-type"
                            name="employment_type_id"
                            options={manage.options.employmentTypes}
                            placeholder="Pilih"
                            defaultValue={
                                manage.values.employment_type_id ?? ''
                            }
                            required
                        />
                    </Field>
                    <Field
                        id="s-class"
                        label="Status kepegawaian baru"
                        error={errors.classification_id}
                    >
                        <SelectOptions
                            id="s-class"
                            name="classification_id"
                            options={manage.options.classifications}
                            placeholder="Tidak diisi"
                            defaultValue={manage.values.classification_id ?? ''}
                        />
                    </Field>
                    <Field
                        id="s-date"
                        label="Berlaku mulai"
                        required
                        error={errors.effective_date}
                    >
                        <Input
                            id="s-date"
                            name="effective_date"
                            type="date"
                            min={nextDay(manage.values.period_start)}
                            max={manage.today}
                            required
                        />
                    </Field>
                </>
            )}
        </ActionForm>
    );
}

// ── Penempatan ───────────────────────────────────────────────────────────────

export function AddPlacementForm({ employeeId, manage, onDone }: ActionProps) {
    return (
        <ActionForm
            title="Tambah penempatan"
            action={`/pegawai/${employeeId}/penempatan`}
            errorBag="addPlacement"
            submitLabel="Tambah penempatan"
            onDone={onDone}
            note="Untuk jabatan tambahan, mis. guru yang juga mengajar di MDA. Untuk pindah lembaga, pakai Mutasi di penempatan yang lama."
        >
            {(errors) => (
                <>
                    <OrganizationPositionFields
                        manage={manage}
                        errors={errors}
                        prefix="a"
                    />
                    <Field
                        id="a-start"
                        label="Mulai"
                        required
                        error={errors.start_date}
                    >
                        <Input
                            id="a-start"
                            name="start_date"
                            type="date"
                            min={manage.values.period_start?.slice(0, 10)}
                            max={manage.today}
                            required
                        />
                    </Field>
                    <label className="flex cursor-pointer items-center gap-2 self-end text-sm">
                        <input
                            type="checkbox"
                            name="make_primary"
                            value="1"
                            className="size-4 accent-primary"
                        />
                        Jadikan penempatan utama
                    </label>
                </>
            )}
        </ActionForm>
    );
}

export function TransferForm({
    employeeId,
    placement,
    manage,
    onDone,
}: ActionProps & { placement: PlacementRef }) {
    return (
        <ActionForm
            title={`Mutasi dari ${placement.position} · ${placement.organization}`}
            action={`/pegawai/${employeeId}/penempatan/${placement.id}/mutasi`}
            errorBag={`transfer-${placement.id}`}
            submitLabel="Simpan mutasi"
            onDone={onDone}
            note="Penempatan lama ditutup sehari sebelum tanggal mutasi; penempatan baru dimulai pada tanggal mutasi."
        >
            {(errors) => (
                <>
                    <OrganizationPositionFields
                        manage={manage}
                        errors={errors}
                        prefix={`t-${placement.id}`}
                    />
                    <Field
                        id={`t-${placement.id}-date`}
                        label="Tanggal mutasi"
                        required
                        error={errors.effective_date}
                    >
                        <Input
                            id={`t-${placement.id}-date`}
                            name="effective_date"
                            type="date"
                            min={nextDay(placement.start_date)}
                            max={manage.today}
                            required
                        />
                    </Field>
                </>
            )}
        </ActionForm>
    );
}

export function EndPlacementForm({
    employeeId,
    placement,
    others,
    manage,
    onDone,
}: ActionProps & { placement: PlacementRef; others: PlacementRef[] }) {
    const needsReplacement = placement.is_primary && others.length > 0;

    return (
        <ActionForm
            title={`Akhiri ${placement.position} · ${placement.organization}`}
            action={`/pegawai/${employeeId}/penempatan/${placement.id}/akhiri`}
            errorBag={`endPlacement-${placement.id}`}
            submitLabel="Akhiri penempatan"
            destructive
            onDone={onDone}
            note={
                others.length === 0
                    ? 'Ini penempatan terakhir. Setelah diakhiri, pegawai hanya terlihat oleh Admin SDM/Admin Yayasan sampai diberi penempatan baru.'
                    : undefined
            }
        >
            {(errors) => (
                <>
                    <Field
                        id={`e-${placement.id}-date`}
                        label="Tanggal berakhir"
                        required
                        error={errors.end_date}
                    >
                        <Input
                            id={`e-${placement.id}-date`}
                            name="end_date"
                            type="date"
                            min={placement.start_date.slice(0, 10)}
                            max={manage.today}
                            required
                        />
                    </Field>
                    {needsReplacement && (
                        <Field
                            id={`e-${placement.id}-primary`}
                            label="Penempatan utama pengganti"
                            required
                            error={errors.new_primary_id}
                        >
                            <SelectOptions
                                id={`e-${placement.id}-primary`}
                                name="new_primary_id"
                                options={others.map((other) => ({
                                    value: other.id,
                                    label: `${other.position} · ${other.organization}`,
                                }))}
                                placeholder="Pilih"
                                required
                            />
                        </Field>
                    )}
                </>
            )}
        </ActionForm>
    );
}

export function MakePrimaryButton({
    employeeId,
    placementId,
}: {
    employeeId: string;
    placementId: string;
}) {
    return (
        <Form
            action={`/pegawai/${employeeId}/penempatan/${placementId}/utama`}
            method="post"
            errorBag={`primary-${placementId}`}
            options={{ preserveScroll: true }}
        >
            {({ processing, errors }) => (
                <>
                    <Button
                        type="submit"
                        variant="outline"
                        size="sm"
                        disabled={processing}
                        className="w-full"
                    >
                        Jadikan utama
                    </Button>
                    <InputError message={errors.form} />
                </>
            )}
        </Form>
    );
}

// ── Berhenti & aktif kembali ─────────────────────────────────────────────────

export function EndEmploymentForm({ employeeId, manage, onDone }: ActionProps) {
    return (
        <ActionForm
            title="Berhentikan pegawai"
            action={`/pegawai/${employeeId}/berhenti`}
            errorBag="endEmployment"
            submitLabel="Ya, berhentikan"
            destructive
            onDone={onDone}
            note="Periode kepegawaian dan semua penempatan yang berjalan ditutup. Data tetap tersimpan. Akun login (bila ada) tidak berubah; atur aksesnya terpisah."
        >
            {(errors) => (
                <>
                    <Field
                        id="b-reason"
                        label="Alasan"
                        required
                        error={errors.end_reason}
                    >
                        <SelectOptions
                            id="b-reason"
                            name="end_reason"
                            options={manage.options.endReasons}
                            placeholder="Pilih alasan"
                            required
                        />
                    </Field>
                    <Field
                        id="b-date"
                        label="Tanggal berhenti"
                        required
                        error={errors.end_date}
                    >
                        <Input
                            id="b-date"
                            name="end_date"
                            type="date"
                            min={manage.values.period_start?.slice(0, 10)}
                            max={manage.today}
                            required
                        />
                    </Field>
                    <Field
                        id="b-note"
                        label="Catatan"
                        error={errors.end_note}
                        className="sm:col-span-2"
                    >
                        <textarea
                            id="b-note"
                            name="end_note"
                            maxLength={500}
                            rows={2}
                            className={textareaClass}
                        />
                    </Field>
                </>
            )}
        </ActionForm>
    );
}

export function RehireForm({ employeeId, manage, onDone }: ActionProps) {
    return (
        <ActionForm
            title="Aktifkan kembali"
            action={`/pegawai/${employeeId}/aktifkan`}
            errorBag="rehire"
            submitLabel="Aktifkan kembali"
            onDone={onDone}
            note="Membuka periode kepegawaian baru dan penempatan utama baru. Riwayat lama tetap tersimpan."
        >
            {(errors) => (
                <>
                    <Field
                        id="r-type"
                        label="Jenis pegawai"
                        required
                        error={errors.employment_type_id}
                    >
                        <SelectOptions
                            id="r-type"
                            name="employment_type_id"
                            options={manage.options.employmentTypes}
                            placeholder="Pilih"
                            required
                        />
                    </Field>
                    <Field
                        id="r-class"
                        label="Status kepegawaian"
                        error={errors.classification_id}
                    >
                        <SelectOptions
                            id="r-class"
                            name="classification_id"
                            options={manage.options.classifications}
                            placeholder="Tidak diisi"
                        />
                    </Field>
                    <OrganizationPositionFields
                        manage={manage}
                        errors={errors}
                        prefix="r"
                    />
                    <Field
                        id="r-start"
                        label="Tanggal mulai"
                        required
                        error={errors.start_date}
                    >
                        <Input
                            id="r-start"
                            name="start_date"
                            type="date"
                            max={manage.today}
                            required
                        />
                    </Field>
                </>
            )}
        </ActionForm>
    );
}

// ── Bagian bersama ───────────────────────────────────────────────────────────

function ActionForm({
    title,
    action,
    method = 'post',
    errorBag,
    submitLabel,
    destructive = false,
    note,
    onDone,
    children,
}: {
    title: string;
    action: string;
    method?: 'post' | 'patch';
    errorBag: string;
    submitLabel: string;
    destructive?: boolean;
    note?: string;
    onDone: () => void;
    children: (errors: Record<string, string | undefined>) => React.ReactNode;
}) {
    return (
        <Form
            action={action}
            method={method}
            errorBag={errorBag}
            options={{ preserveScroll: true }}
            onSuccess={onDone}
            className="space-y-4 rounded-xl border border-primary/30 bg-muted/20 p-4"
        >
            {({ processing, errors }) => (
                <>
                    <div className="space-y-1">
                        <h3 className="text-base font-medium">{title}</h3>
                        {note && (
                            <p className="text-sm text-muted-foreground">
                                {note}
                            </p>
                        )}
                    </div>

                    <InputError message={errors.form} />

                    <div className={gridClass}>{children(errors)}</div>

                    <div className="grid grid-cols-[repeat(auto-fit,minmax(9rem,1fr))] gap-2 sm:flex">
                        <Button
                            type="submit"
                            variant={destructive ? 'destructive' : 'default'}
                            disabled={processing}
                        >
                            {processing ? 'Menyimpan…' : submitLabel}
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

function OrganizationPositionFields({
    manage,
    errors,
    prefix,
}: {
    manage: ManageData;
    errors: Record<string, string | undefined>;
    prefix: string;
}) {
    return (
        <>
            <Field
                id={`${prefix}-org`}
                label="Lembaga"
                required
                error={errors.organization_id}
            >
                <SelectOptions
                    id={`${prefix}-org`}
                    name="organization_id"
                    options={manage.options.organizations}
                    placeholder="Pilih lembaga"
                    required
                />
            </Field>
            <Field
                id={`${prefix}-pos`}
                label="Jabatan"
                required
                error={errors.position_id}
            >
                <SelectOptions
                    id={`${prefix}-pos`}
                    name="position_id"
                    options={manage.options.positions}
                    placeholder="Pilih jabatan"
                    required
                />
            </Field>
        </>
    );
}

function Field({
    id,
    label,
    required = false,
    error,
    className = '',
    children,
}: {
    id: string;
    label: string;
    required?: boolean;
    error?: string;
    className?: string;
    children: React.ReactNode;
}) {
    return (
        <div className={'grid content-start gap-1.5 ' + className}>
            <Label htmlFor={id}>
                {label}
                {required && (
                    <span className="text-red-600 dark:text-red-400"> *</span>
                )}
            </Label>
            {children}
            <InputError message={error} />
        </div>
    );
}

function SelectOptions({
    id,
    name,
    options,
    placeholder,
    defaultValue = '',
    required = false,
    onChange,
}: {
    id: string;
    name: string;
    options: Option[];
    placeholder: string;
    defaultValue?: string;
    required?: boolean;
    onChange?: (value: string) => void;
}) {
    return (
        <select
            id={id}
            name={name}
            className={selectClass}
            defaultValue={defaultValue}
            required={required}
            onChange={(event) => onChange?.(event.target.value)}
        >
            <option value="" disabled={required}>
                {placeholder}
            </option>
            {options.map((option) => (
                <option key={option.value} value={option.value}>
                    {option.label}
                </option>
            ))}
        </select>
    );
}

/** "2020-07-01" → "2020-07-02" (batas bawah input tanggal), tanpa geser zona waktu. */
function nextDay(value: string | null | undefined): string | undefined {
    if (!value) {
        return undefined;
    }

    const [year, month, day] = value.slice(0, 10).split('-').map(Number);
    const next = new Date(Date.UTC(year, month - 1, day + 1));

    return next.toISOString().slice(0, 10);
}
