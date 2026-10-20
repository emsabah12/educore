import { Form, Head, Link } from '@inertiajs/react';
import {
    ArrowLeft,
    CircleAlert,
    Plus,
    Trash2,
    TriangleAlert,
    UserPlus,
} from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Option = { value: string; label: string };

type Candidate = { membership_id: string; label: string };

type Props = {
    today: string;
    candidates: Candidate[];
    options: {
        organizations: Option[];
        genders: Option[];
        entryTypes: Option[];
        relations: Option[];
        /** Kelas aktif tahun ajaran berjalan per lembaga (hanya lembaga yang kelasnya boleh dikelola). */
        classes: Record<string, Option[]>;
    };
    maxGuardians: number;
};

/** Nama isian untuk ringkasan kesalahan (agar operator tahu persis bagian mana yang salah). */
const FIELD_LABELS: Record<string, string> = {
    name: 'Nama lengkap',
    gender: 'Jenis kelamin',
    birth_place: 'Tempat lahir',
    birth_date: 'Tanggal lahir',
    phone: 'Nomor HP siswa',
    nisn: 'NISN',
    address: 'Alamat',
    organization_id: 'Lembaga',
    student_number: 'Nomor induk siswa',
    start_date: 'Tanggal masuk',
    entry_type: 'Jenis masuk',
    guardians: 'Orang tua/wali',
    primary_guardian: 'Kontak utama',
    class_id: 'Kelas',
    existing_membership_id: 'Pilihan orang dengan nama sama',
    form: 'Formulir',
};

const GUARDIAN_FIELD_LABELS: Record<string, string> = {
    relation: 'hubungan',
    name: 'nama',
    gender: 'jenis kelamin',
    phone: 'nomor HP',
};

/** "guardians.1.phone" → "Orang tua/wali ke-2 — nomor HP". */
function fieldLabel(field: string): string {
    const guardian = /^guardians\.(\d+)\.(\w+)$/.exec(field);

    if (guardian) {
        return `Orang tua/wali ke-${Number(guardian[1]) + 1} — ${GUARDIAN_FIELD_LABELS[guardian[2]] ?? guardian[2]}`;
    }

    return FIELD_LABELS[field] ?? field;
}

/** '' = belum memilih, 'baru' = orang berbeda, selain itu = membership calon yang dipilih. */
type DuplicateChoice = string;

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:opacity-50 dark:bg-input/30';

const textareaClass =
    'min-h-20 w-full rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm dark:bg-input/30';

/**
 * Form tambah siswa (PRD-002 §6, AC-1b). Validasi akhir & hak akses tetap di server.
 * Sekali simpan: orang (bila baru), profil siswa, pendaftaran di lembaga, dan orang tua/wali.
 */
export default function CreateStudent({
    today,
    candidates,
    options,
    maxGuardians,
}: Props) {
    const [choice, setChoice] = useState<DuplicateChoice>('');
    // Baris orang tua/wali: kunci stabil agar isian yang sudah diketik tidak tertukar saat baris dihapus.
    const [guardianKeys, setGuardianKeys] = useState<number[]>([]);
    const [nextKey, setNextKey] = useState(0);
    const [primary, setPrimary] = useState(0);

    const addGuardian = () => {
        setGuardianKeys([...guardianKeys, nextKey]);
        setNextKey(nextKey + 1);
    };

    const removeGuardian = (index: number) => {
        setGuardianKeys(
            guardianKeys.filter((_, position) => position !== index),
        );
        setPrimary((current) =>
            current === index ? 0 : current > index ? current - 1 : current,
        );
    };

    const reset = () => {
        setChoice('');
        setGuardianKeys([]);
        setPrimary(0);
    };

    const singleOrganization =
        options.organizations.length === 1
            ? options.organizations[0].value
            : '';
    // Lembaga terpilih menentukan pilihan kelas (AC-1d-b).
    const [organizationId, setOrganizationId] = useState(singleOrganization);
    const classOptions = options.classes[organizationId] ?? [];

    return (
        <>
            <Head title="Tambah siswa" />

            <div className="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-4 p-4">
                <div>
                    <Link
                        href="/siswa"
                        className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                    >
                        <ArrowLeft className="size-4" />
                        Daftar siswa
                    </Link>
                </div>

                <Heading
                    title="Tambah siswa"
                    description="Sekali simpan: data diri, pendaftaran di lembaga, dan orang tua/wali. Tidak membuat akun login."
                />

                {options.organizations.length === 0 && (
                    <p className="rounded-md border border-amber-500/50 bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
                        Tidak ada lembaga aktif yang bisa Anda kelola di lembaga
                        kerja ini.
                    </p>
                )}

                <Form
                    action="/siswa"
                    method="post"
                    resetOnSuccess
                    options={{ preserveScroll: true }}
                    onSuccess={reset}
                    className="space-y-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <InputError message={errors.form} />

                            <Section title="Data diri">
                                <Field
                                    id="name"
                                    label="Nama lengkap"
                                    required
                                    error={errors.name}
                                >
                                    <Input
                                        id="name"
                                        name="name"
                                        maxLength={200}
                                        autoComplete="off"
                                        required
                                        onChange={() => setChoice('')}
                                    />
                                </Field>
                                <Field
                                    id="gender"
                                    label="Jenis kelamin"
                                    required
                                    error={errors.gender}
                                >
                                    <SelectOptions
                                        id="gender"
                                        name="gender"
                                        options={options.genders}
                                        placeholder="Pilih jenis kelamin"
                                        required
                                    />
                                </Field>
                                <Field
                                    id="birth_place"
                                    label="Tempat lahir"
                                    error={errors.birth_place}
                                >
                                    <Input
                                        id="birth_place"
                                        name="birth_place"
                                        maxLength={100}
                                    />
                                </Field>
                                <Field
                                    id="birth_date"
                                    label="Tanggal lahir"
                                    error={errors.birth_date}
                                >
                                    <Input
                                        id="birth_date"
                                        name="birth_date"
                                        type="date"
                                        max={today}
                                    />
                                </Field>
                                <Field
                                    id="phone"
                                    label="Nomor HP siswa"
                                    error={errors.phone}
                                >
                                    <Input
                                        id="phone"
                                        name="phone"
                                        inputMode="tel"
                                        placeholder="mis. 081234567890"
                                        maxLength={25}
                                    />
                                </Field>
                                <Field
                                    id="nisn"
                                    label="NISN"
                                    error={errors.nisn}
                                >
                                    <Input
                                        id="nisn"
                                        name="nisn"
                                        inputMode="numeric"
                                        placeholder="10 angka"
                                        maxLength={14}
                                    />
                                </Field>
                                <Field
                                    id="address"
                                    label="Alamat"
                                    error={errors.address}
                                    className="sm:col-span-2"
                                >
                                    <textarea
                                        id="address"
                                        name="address"
                                        maxLength={500}
                                        className={textareaClass}
                                    />
                                </Field>
                            </Section>

                            <Section title="Pendaftaran">
                                <Field
                                    id="organization_id"
                                    label="Lembaga"
                                    required
                                    error={errors.organization_id}
                                >
                                    <select
                                        id="organization_id"
                                        name="organization_id"
                                        className={selectClass}
                                        value={organizationId}
                                        onChange={(event) =>
                                            setOrganizationId(
                                                event.target.value,
                                            )
                                        }
                                        required
                                    >
                                        <option value="" disabled>
                                            Pilih lembaga
                                        </option>
                                        {options.organizations.map((option) => (
                                            <option
                                                key={option.value}
                                                value={option.value}
                                            >
                                                {option.label}
                                            </option>
                                        ))}
                                    </select>
                                </Field>
                                <Field
                                    id="student_number"
                                    label="Nomor induk siswa"
                                    error={errors.student_number}
                                >
                                    <Input
                                        id="student_number"
                                        name="student_number"
                                        maxLength={30}
                                        className="uppercase"
                                        placeholder="mis. 2026.001"
                                    />
                                </Field>
                                <Field
                                    id="start_date"
                                    label="Tanggal masuk"
                                    required
                                    error={errors.start_date}
                                >
                                    <Input
                                        id="start_date"
                                        name="start_date"
                                        type="date"
                                        max={today}
                                        defaultValue={today}
                                        required
                                    />
                                </Field>
                                <Field
                                    id="entry_type"
                                    label="Jenis masuk"
                                    required
                                    error={errors.entry_type}
                                >
                                    <SelectOptions
                                        id="entry_type"
                                        name="entry_type"
                                        options={options.entryTypes}
                                        placeholder="Pilih jenis masuk"
                                        defaultValue={
                                            options.entryTypes[0]?.value ?? ''
                                        }
                                        required
                                    />
                                </Field>
                                <Field
                                    id="class_id"
                                    label="Kelas (tahun ajaran berjalan)"
                                    error={errors.class_id}
                                >
                                    {/* key: pilihan kelas dikosongkan saat lembaga diganti. */}
                                    <SelectOptions
                                        key={organizationId}
                                        id="class_id"
                                        name="class_id"
                                        options={classOptions}
                                        placeholder={
                                            organizationId === ''
                                                ? 'Pilih lembaga dulu'
                                                : classOptions.length === 0
                                                  ? 'Belum ada kelas aktif'
                                                  : 'Tanpa kelas dulu'
                                        }
                                    />
                                </Field>
                            </Section>

                            <section className="space-y-3 rounded-xl border p-4">
                                <div>
                                    <h2 className="text-base font-medium">
                                        Orang tua/wali
                                    </h2>
                                    <p className="text-sm text-muted-foreground">
                                        Opsional, paling banyak {maxGuardians}.
                                        Bila nama dan nomor HP sama persis
                                        dengan data yang sudah ada (mis. orang
                                        tua kakaknya), data itu dipakai ulang.
                                    </p>
                                </div>

                                {guardianKeys.map((key, index) => (
                                    <GuardianRow
                                        key={key}
                                        index={index}
                                        rowKey={key}
                                        options={options}
                                        errors={errors}
                                        isPrimary={primary === index}
                                        onPrimary={() => setPrimary(index)}
                                        onRemove={() => removeGuardian(index)}
                                    />
                                ))}

                                {guardianKeys.length > 0 && (
                                    <input
                                        type="hidden"
                                        name="primary_guardian"
                                        value={primary}
                                    />
                                )}

                                <InputError
                                    message={
                                        errors.guardians ??
                                        errors.primary_guardian
                                    }
                                />

                                {guardianKeys.length < maxGuardians && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={addGuardian}
                                    >
                                        <Plus />
                                        Tambah orang tua/wali
                                    </Button>
                                )}
                            </section>

                            <ErrorSummary errors={errors} />

                            {(errors.duplicate || candidates.length > 0) && (
                                <DuplicateBox
                                    message={errors.duplicate}
                                    candidates={candidates}
                                    choice={choice}
                                    onChoose={setChoice}
                                    error={errors.existing_membership_id}
                                />
                            )}

                            {choice === 'baru' && (
                                <input
                                    type="hidden"
                                    name="confirm_new"
                                    value="1"
                                />
                            )}
                            {choice !== '' && choice !== 'baru' && (
                                <input
                                    type="hidden"
                                    name="existing_membership_id"
                                    value={choice}
                                />
                            )}

                            <div className="flex flex-wrap gap-2">
                                <Button
                                    type="submit"
                                    disabled={
                                        processing ||
                                        options.organizations.length === 0
                                    }
                                >
                                    <UserPlus />
                                    {processing ? 'Menyimpan…' : 'Simpan siswa'}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

function GuardianRow({
    index,
    rowKey,
    options,
    errors,
    isPrimary,
    onPrimary,
    onRemove,
}: {
    index: number;
    rowKey: number;
    options: Props['options'];
    errors: Record<string, string | undefined>;
    isPrimary: boolean;
    onPrimary: () => void;
    onRemove: () => void;
}) {
    const id = (field: string) => `guardian-${rowKey}-${field}`;
    const name = (field: string) => `guardians[${index}][${field}]`;
    const error = (field: string) => errors[`guardians.${index}.${field}`];

    return (
        <div className="space-y-3 rounded-md border p-3">
            <div className="grid gap-3 sm:grid-cols-2">
                <Field
                    id={id('relation')}
                    label="Hubungan"
                    required
                    error={error('relation')}
                >
                    <SelectOptions
                        id={id('relation')}
                        name={name('relation')}
                        options={options.relations}
                        placeholder="Pilih hubungan"
                        required
                    />
                </Field>
                <Field
                    id={id('name')}
                    label="Nama"
                    required
                    error={error('name')}
                >
                    <Input
                        id={id('name')}
                        name={name('name')}
                        maxLength={200}
                        autoComplete="off"
                        required
                    />
                </Field>
                <Field
                    id={id('gender')}
                    label="Jenis kelamin"
                    error={error('gender')}
                >
                    <SelectOptions
                        id={id('gender')}
                        name={name('gender')}
                        options={options.genders}
                        placeholder="Tidak diisi"
                    />
                </Field>
                <Field id={id('phone')} label="Nomor HP" error={error('phone')}>
                    <Input
                        id={id('phone')}
                        name={name('phone')}
                        inputMode="tel"
                        placeholder="mis. 081234567890"
                        maxLength={25}
                    />
                </Field>
            </div>

            {/* Grid otomatis agar tombol turun baris di HP. */}
            <div className="grid grid-cols-[repeat(auto-fit,minmax(10rem,1fr))] gap-2">
                <label className="flex min-h-9 cursor-pointer items-center gap-2 rounded-md border px-3 text-sm">
                    <input
                        type="radio"
                        checked={isPrimary}
                        onChange={onPrimary}
                        className="size-4 accent-primary"
                    />
                    Kontak utama
                </label>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={onRemove}
                >
                    <Trash2 />
                    Hapus baris
                </Button>
            </div>
        </div>
    );
}

/**
 * Ringkasan semua isian yang perlu diperbaiki, tepat di atas tombol Simpan (posisi layar setelah
 * menyimpan). Peringatan nama sama tampil terpisah di kotak kuning.
 */
function ErrorSummary({
    errors,
}: {
    errors: Record<string, string | undefined>;
}) {
    const entries = Object.entries(errors).filter(
        (entry): entry is [string, string] =>
            entry[0] !== 'duplicate' && typeof entry[1] === 'string',
    );

    if (entries.length === 0) {
        return null;
    }

    return (
        <div
            role="alert"
            className="space-y-2 rounded-md border border-red-500/50 bg-red-50 p-4 text-sm text-red-900 dark:bg-red-950/40 dark:text-red-100"
        >
            <p className="flex gap-2 font-medium">
                <CircleAlert className="mt-0.5 size-4 shrink-0" />
                {entries.length === 1
                    ? 'Ada 1 isian yang perlu diperbaiki:'
                    : `Ada ${entries.length} isian yang perlu diperbaiki:`}
            </p>
            <ul className="list-disc space-y-1 pl-10">
                {entries.map(([field, message]) => (
                    <li key={field}>
                        <span className="font-medium">{fieldLabel(field)}</span>
                        : {message}
                    </li>
                ))}
            </ul>
        </div>
    );
}

function DuplicateBox({
    message,
    candidates,
    choice,
    onChoose,
    error,
}: {
    message?: string;
    candidates: Candidate[];
    choice: DuplicateChoice;
    onChoose: (choice: DuplicateChoice) => void;
    error?: string;
}) {
    return (
        <div
            role="alert"
            className="space-y-3 rounded-md border border-amber-500/50 bg-amber-50 p-4 text-sm text-amber-900 dark:bg-amber-950/40 dark:text-amber-100"
        >
            <p className="flex gap-2">
                <TriangleAlert className="mt-0.5 size-4 shrink-0" />
                <span>
                    {message ??
                        'Sudah ada anggota yayasan dengan nama yang sama. Pilih salah satu di bawah, lalu simpan ulang.'}
                </span>
            </p>

            <div
                role="radiogroup"
                aria-label="Orang dengan nama sama"
                className="space-y-2"
            >
                {candidates.map((candidate) => (
                    <label
                        key={candidate.membership_id}
                        className="flex cursor-pointer items-start gap-2"
                    >
                        <input
                            type="radio"
                            checked={choice === candidate.membership_id}
                            onChange={() => onChoose(candidate.membership_id)}
                            className="mt-0.5 size-4 accent-primary"
                        />
                        <span>
                            <span className="font-medium">
                                Ini orang yang sama:
                            </span>{' '}
                            {candidate.label}
                        </span>
                    </label>
                ))}
                <label className="flex cursor-pointer items-start gap-2">
                    <input
                        type="radio"
                        checked={choice === 'baru'}
                        onChange={() => onChoose('baru')}
                        className="mt-0.5 size-4 accent-primary"
                    />
                    <span className="font-medium">
                        Orang berbeda — buat sebagai orang baru
                    </span>
                </label>
            </div>

            <InputError message={error} />
        </div>
    );
}

function Section({
    title,
    children,
}: {
    title: string;
    children: React.ReactNode;
}) {
    return (
        <section className="space-y-3 rounded-xl border p-4">
            <h2 className="text-base font-medium">{title}</h2>
            <div className="grid gap-4 sm:grid-cols-2">{children}</div>
        </section>
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
