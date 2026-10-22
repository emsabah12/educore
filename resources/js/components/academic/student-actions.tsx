import { Form } from '@inertiajs/react';
import { CircleAlert } from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/*
 * Form aksi di detail siswa (AC-1c-b1, PRD-002 §6). Semua aturan & hak akses tetap dicek server;
 * form di sini hanya membantu (batas tanggal, pilihan yang tersedia).
 */

export type Option = { value: string; label: string };

export type ProfileValues = {
    name: string;
    gender: string | null;
    birth_place: string | null;
    birth_date: string | null;
    phone: string | null;
    address: string | null;
    nisn: string | null;
};

export type StudentManageData = {
    today: string;
    profile: ProfileValues | null;
    guardians: boolean;
    maxGuardians: number;
    enrollTargets: Option[];
    options: {
        genders: Option[];
        entryTypes: Option[];
        endReasons: Option[];
        relations: Option[];
    };
};

export type EnrollmentRef = {
    id: string;
    organization: string;
    student_number: string | null;
    entry_type_code: string;
    start_date: string;
    end_date: string | null;
};

export const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:opacity-50 dark:bg-input/30';

export const textareaClass =
    'min-h-20 w-full rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm dark:bg-input/30';

/** Nama isian untuk ringkasan kesalahan. */
const fieldLabels: Record<string, string> = {
    name: 'Nama lengkap',
    gender: 'Jenis kelamin',
    birth_place: 'Tempat lahir',
    birth_date: 'Tanggal lahir',
    phone: 'Nomor HP',
    address: 'Alamat',
    nisn: 'NISN',
    organization_id: 'Lembaga',
    student_number: 'Nomor induk',
    start_date: 'Tanggal masuk',
    entry_type: 'Jenis masuk',
    end_date: 'Tanggal keluar',
    end_reason: 'Alasan',
    end_note: 'Keterangan',
    reason: 'Alasan',
    relation: 'Hubungan',
    membership_id: 'Orang yang dipilih',
    new_primary_id: 'Kontak utama pengganti',
    code: 'Kode',
    grade_min: 'Tingkat dari',
    grade_max: 'Tingkat sampai',
    subject_ids: 'Mata pelajaran',
    teacher_employee_id: 'Guru pengampu',
    source_class_id: 'Kelas sumber',
    class_subject_id: 'Mata pelajaran',
    day_of_week: 'Hari',
    start_time: 'Jam mulai',
    end_time: 'Jam selesai',
    form: 'Formulir',
};

// ── Ubah data diri ───────────────────────────────────────────────────────────

export function ProfileForm({
    studentId,
    manage,
    values,
    onDone,
}: {
    studentId: string;
    manage: StudentManageData;
    values: ProfileValues;
    onDone: () => void;
}) {
    return (
        <ActionForm
            title="Ubah data diri"
            action={`/siswa/${studentId}`}
            method="patch"
            errorBag="studentProfile"
            submitLabel="Simpan data diri"
            onDone={onDone}
            note="Kolom yang dikosongkan berarti datanya dihapus. Data diri melekat pada orangnya, jadi perubahan nama ikut terlihat di semua lembaga (dan di data pegawai/akun login bila orang ini juga punya)."
        >
            {(errors) => (
                <>
                    <Field
                        id="sp-name"
                        label="Nama lengkap"
                        required
                        error={errors.name}
                    >
                        <Input
                            id="sp-name"
                            name="name"
                            defaultValue={values.name}
                            maxLength={200}
                            required
                        />
                    </Field>
                    <Field
                        id="sp-gender"
                        label="Jenis kelamin"
                        required
                        error={errors.gender}
                    >
                        <SelectOptions
                            id="sp-gender"
                            name="gender"
                            options={manage.options.genders}
                            placeholder="Pilih"
                            defaultValue={values.gender ?? ''}
                            required
                        />
                    </Field>
                    <Field
                        id="sp-birth-place"
                        label="Tempat lahir"
                        error={errors.birth_place}
                    >
                        <Input
                            id="sp-birth-place"
                            name="birth_place"
                            defaultValue={values.birth_place ?? ''}
                            maxLength={100}
                        />
                    </Field>
                    <Field
                        id="sp-birth-date"
                        label="Tanggal lahir"
                        error={errors.birth_date}
                    >
                        <Input
                            id="sp-birth-date"
                            name="birth_date"
                            type="date"
                            max={manage.today}
                            defaultValue={values.birth_date ?? ''}
                        />
                    </Field>
                    <Field id="sp-nisn" label="NISN" error={errors.nisn}>
                        <Input
                            id="sp-nisn"
                            name="nisn"
                            inputMode="numeric"
                            defaultValue={values.nisn ?? ''}
                            maxLength={12}
                        />
                    </Field>
                    <Field id="sp-phone" label="Nomor HP" error={errors.phone}>
                        <Input
                            id="sp-phone"
                            name="phone"
                            type="tel"
                            inputMode="tel"
                            defaultValue={values.phone ?? ''}
                            maxLength={25}
                        />
                    </Field>
                    <Field
                        id="sp-address"
                        label="Alamat"
                        error={errors.address}
                        className="sm:col-span-2"
                    >
                        <textarea
                            id="sp-address"
                            name="address"
                            defaultValue={values.address ?? ''}
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

// ── Daftarkan ke lembaga lain ────────────────────────────────────────────────

export function EnrollForm({
    studentId,
    manage,
    onDone,
}: {
    studentId: string;
    manage: StudentManageData;
    onDone: () => void;
}) {
    return (
        <ActionForm
            title="Daftarkan ke lembaga lain"
            action={`/siswa/${studentId}/pendaftaran`}
            errorBag="enroll"
            submitLabel="Daftarkan"
            onDone={onDone}
            note="Pilihan lembaga hanya lembaga yang boleh Anda kelola dan belum mencatat siswa ini sebagai siswa aktif. Pendaftaran di lembaga lain tidak berubah."
        >
            {(errors) => (
                <>
                    <Field
                        id="en-org"
                        label="Lembaga"
                        required
                        error={errors.organization_id}
                    >
                        <SelectOptions
                            id="en-org"
                            name="organization_id"
                            options={manage.enrollTargets}
                            placeholder="Pilih lembaga"
                            defaultValue={
                                manage.enrollTargets.length === 1
                                    ? manage.enrollTargets[0].value
                                    : ''
                            }
                            required
                        />
                    </Field>
                    <EnrollmentFields
                        prefix="en"
                        manage={manage}
                        errors={errors}
                        maxStart={manage.today}
                    />
                </>
            )}
        </ActionForm>
    );
}

// ── Ubah pendaftaran ─────────────────────────────────────────────────────────

export function EditEnrollmentForm({
    studentId,
    enrollment,
    manage,
    onDone,
}: {
    studentId: string;
    enrollment: EnrollmentRef;
    manage: StudentManageData;
    onDone: () => void;
}) {
    return (
        <ActionForm
            title={`Ubah pendaftaran di ${enrollment.organization}`}
            action={`/siswa/${studentId}/pendaftaran/${enrollment.id}`}
            method="patch"
            errorBag="enrollment"
            submitLabel="Simpan pendaftaran"
            onDone={onDone}
            note="Untuk memperbaiki salah input. Nomor induk yang dikosongkan berarti dihapus."
        >
            {(errors) => (
                <EnrollmentFields
                    prefix="ee"
                    manage={manage}
                    errors={errors}
                    maxStart={enrollment.end_date ?? manage.today}
                    values={enrollment}
                />
            )}
        </ActionForm>
    );
}

// ── Tutup pendaftaran ────────────────────────────────────────────────────────

export function CloseEnrollmentForm({
    studentId,
    enrollment,
    manage,
    onDone,
}: {
    studentId: string;
    enrollment: EnrollmentRef;
    manage: StudentManageData;
    onDone: () => void;
}) {
    return (
        <ActionForm
            title={`Tutup pendaftaran di ${enrollment.organization}`}
            action={`/siswa/${studentId}/pendaftaran/${enrollment.id}/tutup`}
            errorBag="closeEnrollment"
            submitLabel="Tutup pendaftaran"
            destructive
            onDone={onDone}
            note="Siswa tidak lagi aktif di lembaga ini dan ikut keluar dari kelasnya di lembaga ini. Data siswa, riwayat, dan pendaftaran di lembaga lain tetap ada."
        >
            {(errors) => (
                <>
                    <Field
                        id="ce-date"
                        label="Tanggal keluar"
                        required
                        error={errors.end_date}
                    >
                        <Input
                            id="ce-date"
                            name="end_date"
                            type="date"
                            min={enrollment.start_date}
                            max={manage.today}
                            defaultValue={manage.today}
                            required
                        />
                    </Field>
                    <Field
                        id="ce-reason"
                        label="Alasan"
                        required
                        error={errors.end_reason}
                    >
                        <SelectOptions
                            id="ce-reason"
                            name="end_reason"
                            options={manage.options.endReasons}
                            placeholder="Pilih alasan"
                            required
                        />
                    </Field>
                    <Field
                        id="ce-note"
                        label="Keterangan (wajib bila alasan Lainnya)"
                        error={errors.end_note}
                        className="sm:col-span-2"
                    >
                        <textarea
                            id="ce-note"
                            name="end_note"
                            maxLength={500}
                            rows={2}
                            className={textareaClass}
                            placeholder="Mis. pindah ke SMP Negeri 1 Bandung"
                        />
                    </Field>
                </>
            )}
        </ActionForm>
    );
}

// ── Bagian bersama ───────────────────────────────────────────────────────────

function EnrollmentFields({
    prefix,
    manage,
    errors,
    maxStart,
    values,
}: {
    prefix: string;
    manage: StudentManageData;
    errors: Record<string, string | undefined>;
    maxStart: string;
    values?: EnrollmentRef;
}) {
    return (
        <>
            <Field
                id={`${prefix}-number`}
                label="Nomor induk di lembaga ini"
                error={errors.student_number}
            >
                <Input
                    id={`${prefix}-number`}
                    name="student_number"
                    defaultValue={values?.student_number ?? ''}
                    maxLength={30}
                    className="uppercase"
                />
            </Field>
            <Field
                id={`${prefix}-start`}
                label="Tanggal masuk"
                required
                error={errors.start_date}
            >
                <Input
                    id={`${prefix}-start`}
                    name="start_date"
                    type="date"
                    max={maxStart}
                    defaultValue={values?.start_date ?? manage.today}
                    required
                />
            </Field>
            <Field
                id={`${prefix}-entry`}
                label="Jenis masuk"
                required
                error={errors.entry_type}
            >
                <SelectOptions
                    id={`${prefix}-entry`}
                    name="entry_type"
                    options={manage.options.entryTypes}
                    placeholder="Pilih"
                    defaultValue={values?.entry_type_code ?? 'BARU'}
                    required
                />
            </Field>
        </>
    );
}

export function ActionForm({
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
    method?: 'post' | 'patch' | 'delete';
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

                    <div className="grid gap-4 sm:grid-cols-2">
                        {children(errors)}
                    </div>

                    <ErrorSummary errors={errors} />

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

/** Ringkasan di atas tombol simpan agar pengguna tidak menebak isian mana yang salah. */
function ErrorSummary({
    errors,
}: {
    errors: Record<string, string | undefined>;
}) {
    const fields = Object.entries(errors)
        .filter((entry) => typeof entry[1] === 'string')
        .map(([field]) => fieldLabels[field] ?? field);

    if (fields.length === 0) {
        return null;
    }

    return (
        <p
            role="alert"
            className="flex gap-2 rounded-md border border-red-500/50 bg-red-50 p-3 text-sm text-red-900 dark:bg-red-950/40 dark:text-red-100"
        >
            <CircleAlert className="mt-0.5 size-4 shrink-0" />
            <span>
                {fields.length === 1
                    ? 'Ada 1 isian yang perlu diperbaiki: '
                    : `Ada ${fields.length} isian yang perlu diperbaiki: `}
                {fields.join(', ')}.
            </span>
        </p>
    );
}

export function Field({
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

export function SelectOptions({
    id,
    name,
    options,
    placeholder,
    defaultValue = '',
    required = false,
}: {
    id: string;
    name: string;
    options: Option[];
    placeholder: string;
    defaultValue?: string;
    required?: boolean;
}) {
    return (
        <select
            id={id}
            name={name}
            className={selectClass}
            defaultValue={defaultValue}
            required={required}
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

// ── Buka kembali pendaftaran (§18 P2) ────────────────────────────────────────

/** Admin Yayasan saja; alasan wajib. Kelas yang tertutup bersamaan dibuka lagi bila masih bisa. */
export function ReopenEnrollmentForm({
    studentId,
    enrollment,
    onDone,
}: {
    studentId: string;
    enrollment: { id: string; organization: string };
    onDone: () => void;
}) {
    return (
        <ActionForm
            title={`Buka kembali pendaftaran di ${enrollment.organization}`}
            action={`/siswa/${studentId}/pendaftaran/${enrollment.id}/buka-kembali`}
            errorBag="reopenEnrollment"
            submitLabel="Buka kembali"
            onDone={onDone}
            note="Untuk pendaftaran yang salah ditutup. Tanggal & alasan keluar dihapus; kelas yang ikut tertutup pada tanggal itu dibuka lagi bila kelasnya masih bisa diisi."
        >
            {(errors) => (
                <Field
                    id={`reopen-${enrollment.id}`}
                    label="Alasan"
                    required
                    error={errors.reason}
                    className="sm:col-span-2"
                >
                    <textarea
                        id={`reopen-${enrollment.id}`}
                        name="reason"
                        maxLength={500}
                        required
                        className={textareaClass}
                        placeholder="Mis. salah memilih siswa saat menutup pendaftaran"
                    />
                </Field>
            )}
        </ActionForm>
    );
}
