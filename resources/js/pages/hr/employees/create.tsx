import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft, TriangleAlert, UserPlus } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Option = { value: string; label: string };

type Member = {
    id: string;
    name: string;
    email: string | null;
    gender: string | null;
};

type Props = {
    today: string;
    members: Member[];
    options: {
        employmentTypes: Option[];
        classifications: Option[];
        positions: Option[];
        organizations: Option[];
        educationLevels: Option[];
        genders: Option[];
    };
};

type Mode = 'baru' | 'anggota';

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:opacity-50 dark:bg-input/30';

const textareaClass =
    'min-h-20 w-full rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm dark:bg-input/30';

/**
 * Form tambah pegawai (PRD-001 §6). Validasi akhir & hak akses tetap di server.
 * Sekali simpan: orang (bila baru), keanggotaan, profil pegawai, periode, dan penempatan pertama.
 */
export default function CreateEmployee({ today, members, options }: Props) {
    const [mode, setMode] = useState<Mode>('baru');
    const [memberId, setMemberId] = useState('');

    const selectedMember = members.find((member) => member.id === memberId);
    const missingCatalog = [
        options.employmentTypes.length === 0 && 'jenis pegawai',
        options.positions.length === 0 && 'jabatan',
        options.organizations.length === 0 && 'lembaga aktif',
    ].filter((item): item is string => typeof item === 'string');

    return (
        <>
            <Head title="Tambah pegawai" />

            <div className="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-4 p-4">
                <div>
                    <Link
                        href="/pegawai"
                        className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                    >
                        <ArrowLeft className="size-4" />
                        Daftar pegawai
                    </Link>
                </div>

                <Heading
                    title="Tambah pegawai"
                    description="Sekali simpan: data diri, data kepegawaian, dan penempatan pertama. Tidak membuat akun login."
                />

                {missingCatalog.length > 0 && (
                    <p className="rounded-md border border-amber-500/50 bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
                        Belum ada {missingCatalog.join(', ')} yang aktif. Isi
                        atau aktifkan dulu di Katalog SDM / Lembaga.
                    </p>
                )}

                <div
                    role="radiogroup"
                    aria-label="Siapa yang ditambahkan"
                    className="grid grid-cols-[repeat(auto-fit,minmax(11rem,1fr))] gap-2"
                >
                    <ModeButton
                        active={mode === 'baru'}
                        onClick={() => setMode('baru')}
                        title="Orang baru"
                        description="Belum terdaftar di yayasan"
                    />
                    <ModeButton
                        active={mode === 'anggota'}
                        onClick={() => setMode('anggota')}
                        title="Anggota yayasan"
                        description="Sudah terdaftar, mis. sudah punya akun"
                    />
                </div>

                <Form
                    action="/pegawai"
                    method="post"
                    resetOnSuccess
                    options={{ preserveScroll: true }}
                    onSuccess={() => {
                        setMode('baru');
                        setMemberId('');
                    }}
                    className="space-y-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <input type="hidden" name="mode" value={mode} />

                            <InputError message={errors.form} />

                            <Section title="Data diri">
                                {mode === 'baru' ? (
                                    <>
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
                                            />
                                        </Field>
                                        <Field
                                            id="gender"
                                            label="Jenis kelamin"
                                            required
                                            error={errors.gender}
                                        >
                                            <select
                                                id="gender"
                                                name="gender"
                                                className={selectClass}
                                                defaultValue=""
                                                required
                                            >
                                                <option value="" disabled>
                                                    Pilih
                                                </option>
                                                {options.genders.map(
                                                    (option) => (
                                                        <option
                                                            key={option.value}
                                                            value={option.value}
                                                        >
                                                            {option.label}
                                                        </option>
                                                    ),
                                                )}
                                            </select>
                                        </Field>
                                    </>
                                ) : (
                                    <>
                                        <Field
                                            id="membership_id"
                                            label="Anggota yayasan"
                                            required
                                            error={errors.membership_id}
                                            className="sm:col-span-2"
                                        >
                                            {members.length === 0 ? (
                                                <p className="text-sm text-muted-foreground">
                                                    Semua anggota yayasan sudah
                                                    punya profil pegawai. Pilih
                                                    &quot;Orang baru&quot;.
                                                </p>
                                            ) : (
                                                <select
                                                    id="membership_id"
                                                    name="membership_id"
                                                    className={selectClass}
                                                    value={memberId}
                                                    onChange={(event) =>
                                                        setMemberId(
                                                            event.target.value,
                                                        )
                                                    }
                                                    required
                                                >
                                                    <option value="" disabled>
                                                        Pilih anggota
                                                    </option>
                                                    {members.map((member) => (
                                                        <option
                                                            key={member.id}
                                                            value={member.id}
                                                        >
                                                            {member.name}
                                                            {member.email
                                                                ? ` — ${member.email}`
                                                                : ''}
                                                        </option>
                                                    ))}
                                                </select>
                                            )}
                                        </Field>
                                        {selectedMember &&
                                            selectedMember.gender === null && (
                                                <Field
                                                    id="member-gender"
                                                    label="Jenis kelamin"
                                                    required
                                                    error={errors.gender}
                                                >
                                                    <select
                                                        id="member-gender"
                                                        name="gender"
                                                        className={selectClass}
                                                        defaultValue=""
                                                        required
                                                    >
                                                        <option
                                                            value=""
                                                            disabled
                                                        >
                                                            Pilih
                                                        </option>
                                                        {options.genders.map(
                                                            (option) => (
                                                                <option
                                                                    key={
                                                                        option.value
                                                                    }
                                                                    value={
                                                                        option.value
                                                                    }
                                                                >
                                                                    {
                                                                        option.label
                                                                    }
                                                                </option>
                                                            ),
                                                        )}
                                                    </select>
                                                </Field>
                                            )}
                                        <p className="text-xs text-muted-foreground sm:col-span-2">
                                            Data pribadi di bawah melengkapi
                                            data orang ini. Kolom yang
                                            dikosongkan tidak mengubah data
                                            lamanya.
                                        </p>
                                    </>
                                )}

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
                                    label="Nomor HP"
                                    error={errors.phone}
                                >
                                    <Input
                                        id="phone"
                                        name="phone"
                                        type="tel"
                                        inputMode="tel"
                                        placeholder="mis. 0812-3456-7890"
                                        maxLength={25}
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
                                        rows={2}
                                        className={textareaClass}
                                    />
                                </Field>
                            </Section>

                            <Section title="Data kepegawaian">
                                <Field
                                    id="employee_number"
                                    label="Nomor induk yayasan"
                                    error={errors.employee_number}
                                >
                                    <Input
                                        id="employee_number"
                                        name="employee_number"
                                        maxLength={30}
                                        className="uppercase"
                                    />
                                </Field>
                                <Field
                                    id="nuptk"
                                    label="NUPTK"
                                    error={errors.nuptk}
                                >
                                    <Input
                                        id="nuptk"
                                        name="nuptk"
                                        inputMode="numeric"
                                        maxLength={19}
                                        placeholder="16 angka"
                                    />
                                </Field>
                                <Field
                                    id="education_level"
                                    label="Pendidikan terakhir"
                                    error={errors.education_level}
                                >
                                    <SelectOptions
                                        id="education_level"
                                        name="education_level"
                                        options={options.educationLevels}
                                        placeholder="Tidak diisi"
                                    />
                                </Field>
                                <Field
                                    id="education_major"
                                    label="Program studi/jurusan"
                                    error={errors.education_major}
                                >
                                    <Input
                                        id="education_major"
                                        name="education_major"
                                        maxLength={150}
                                    />
                                </Field>
                                <Field
                                    id="employment_type_id"
                                    label="Jenis pegawai"
                                    required
                                    error={errors.employment_type_id}
                                >
                                    <SelectOptions
                                        id="employment_type_id"
                                        name="employment_type_id"
                                        options={options.employmentTypes}
                                        placeholder="Pilih"
                                        required
                                    />
                                </Field>
                                <Field
                                    id="classification_id"
                                    label="Status kepegawaian"
                                    error={errors.classification_id}
                                >
                                    <SelectOptions
                                        id="classification_id"
                                        name="classification_id"
                                        options={options.classifications}
                                        placeholder="Tidak diisi"
                                    />
                                </Field>
                                <Field
                                    id="start_date"
                                    label="Tanggal mulai bekerja"
                                    required
                                    error={errors.start_date}
                                >
                                    <Input
                                        id="start_date"
                                        name="start_date"
                                        type="date"
                                        max={today}
                                        required
                                    />
                                </Field>
                            </Section>

                            <Section
                                title="Penempatan pertama"
                                description="Menjadi penempatan utama. Jabatan tidak memberi hak akses apa pun di aplikasi."
                            >
                                <Field
                                    id="organization_id"
                                    label="Lembaga"
                                    required
                                    error={errors.organization_id}
                                >
                                    <SelectOptions
                                        id="organization_id"
                                        name="organization_id"
                                        options={options.organizations}
                                        placeholder="Pilih lembaga"
                                        required
                                    />
                                </Field>
                                <Field
                                    id="position_id"
                                    label="Jabatan"
                                    required
                                    error={errors.position_id}
                                >
                                    <SelectOptions
                                        id="position_id"
                                        name="position_id"
                                        options={options.positions}
                                        placeholder="Pilih jabatan"
                                        required
                                    />
                                </Field>
                            </Section>

                            {errors.duplicate && (
                                <div
                                    role="alert"
                                    className="space-y-3 rounded-md border border-amber-500/50 bg-amber-50 p-4 text-sm text-amber-900 dark:bg-amber-950/40 dark:text-amber-100"
                                >
                                    <p className="flex gap-2">
                                        <TriangleAlert className="mt-0.5 size-4 shrink-0" />
                                        <span>{errors.duplicate}</span>
                                    </p>
                                    <label className="flex cursor-pointer items-start gap-2">
                                        <input
                                            type="checkbox"
                                            name="confirm_duplicate"
                                            value="1"
                                            className="mt-0.5 size-4 accent-primary"
                                        />
                                        Saya sudah memeriksa: ini orang yang
                                        berbeda.
                                    </label>
                                </div>
                            )}

                            <div className="flex flex-wrap gap-2">
                                <Button
                                    type="submit"
                                    disabled={
                                        processing || missingCatalog.length > 0
                                    }
                                >
                                    <UserPlus />
                                    {processing
                                        ? 'Menyimpan…'
                                        : 'Simpan pegawai'}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

function ModeButton({
    active,
    onClick,
    title,
    description,
}: {
    active: boolean;
    onClick: () => void;
    title: string;
    description: string;
}) {
    return (
        <button
            type="button"
            role="radio"
            aria-checked={active}
            onClick={onClick}
            className={
                'min-h-14 rounded-md border px-3 py-2 text-left transition-colors ' +
                (active
                    ? 'border-primary bg-primary text-primary-foreground'
                    : 'hover:bg-muted')
            }
        >
            <span className="block text-sm font-medium">{title}</span>
            <span
                className={
                    'block text-xs ' +
                    (active
                        ? 'text-primary-foreground/80'
                        : 'text-muted-foreground')
                }
            >
                {description}
            </span>
        </button>
    );
}

function Section({
    title,
    description,
    children,
}: {
    title: string;
    description?: string;
    children: React.ReactNode;
}) {
    return (
        <section className="space-y-3 rounded-xl border p-4">
            <div>
                <h2 className="text-base font-medium">{title}</h2>
                {description && (
                    <p className="text-sm text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>
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
    required = false,
}: {
    id: string;
    name: string;
    options: Option[];
    placeholder: string;
    required?: boolean;
}) {
    return (
        <select
            id={id}
            name={name}
            className={selectClass}
            defaultValue=""
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
