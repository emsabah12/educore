import { Form, Head, Link } from '@inertiajs/react';
import {
    ArrowLeft,
    Check,
    Download,
    MessageSquarePlus,
    Pencil,
    Plus,
    RotateCcw,
    Trash2,
    UserCheck,
    UserPlus,
    X,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { ConfirmAction } from '@/components/confirm-action';
import { FormDialog } from '@/components/form-dialog';
import Heading from '@/components/heading';
import type {
    Option,
    RecruitmentLimits,
    Vacancy,
} from '@/components/hr/vacancy-form';
import {
    VacancyForm,
    VacancyStatusBadge,
    formatRecruitmentDate,
    textareaClass,
} from '@/components/hr/vacancy-form';
import InputError from '@/components/input-error';
import { selectClass } from '@/components/native-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Step = {
    action: string;
    action_label: string;
    stage_label: string;
    score: number | null;
    note: string | null;
    by: string | null;
    at: string;
};

type Applicant = {
    id: string;
    name: string;
    gender: string;
    phone: string | null;
    email: string | null;
    education_level: string | null;
    education_label: string | null;
    education_major: string | null;
    note: string | null;
    stage: 'BERKAS' | 'TES' | 'DITERIMA' | 'TIDAK_LOLOS';
    stage_label: string;
    file_name: string | null;
    added_on: string;
    employee_id: string | null;
    steps: Step[];
};

type Props = {
    vacancy: Vacancy;
    applicants: Applicant[];
    canManage: boolean;
    canHire: boolean;
    options: {
        organizations: Option[];
        positions: Option[];
        genders: Option[];
        educationLevels: Option[];
        stages: Option[];
    };
    limits: RecruitmentLimits;
};

const STAGE_ORDER: Applicant['stage'][] = [
    'TES',
    'BERKAS',
    'DITERIMA',
    'TIDAK_LOLOS',
];

/**
 * Detail lowongan (PRD-001 §20.1, HR-9a): info & status lowongan, pelamar per tahap, aksi seleksi lewat pop-up.
 * Kepala Lembaga hanya melihat dan menambah catatan wawancara.
 */
export default function RecruitmentShow({
    vacancy,
    applicants,
    canManage,
    canHire,
    options,
    limits,
}: Props) {
    const [editing, setEditing] = useState(false);
    const [adding, setAdding] = useState(false);
    const base = `/pegawai/rekrutmen/${vacancy.id}`;

    return (
        <>
            <Head title={vacancy.title} />

            <div className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-4 p-4">
                <Link
                    href="/pegawai/rekrutmen"
                    className="inline-flex w-fit items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" />
                    Semua lowongan
                </Link>

                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading
                        title={vacancy.title}
                        description={`${vacancy.organization} · ${vacancy.position} · dibutuhkan ${vacancy.openings} orang${vacancy.closes_on ? ` · batas lamaran ${formatRecruitmentDate(vacancy.closes_on)}` : ''}`}
                    />
                    <VacancyStatusBadge
                        status={vacancy.status}
                        label={vacancy.status_label}
                    />
                </div>

                <p className="text-sm text-muted-foreground">
                    Diterima{' '}
                    {(vacancy.counts.DITERIMA ?? 0) +
                        (vacancy.purged.DITERIMA ?? 0)}{' '}
                    dari {vacancy.openings} dibutuhkan · {vacancy.hired} sudah
                    dijadikan pegawai
                    {vacancy.purged_total > 0 &&
                        ` · ${vacancy.purged_total} data pelamar lama sudah dihapus otomatis`}
                </p>

                {vacancy.requirements && (
                    <section className="space-y-1 rounded-xl border p-4 text-sm">
                        <h2 className="font-medium">Persyaratan</h2>
                        <p className="break-words whitespace-pre-line text-muted-foreground">
                            {vacancy.requirements}
                        </p>
                    </section>
                )}

                {canManage && (
                    <div className="grid grid-cols-[repeat(auto-fit,minmax(10rem,1fr))] gap-2 sm:flex sm:flex-wrap">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setEditing(true)}
                        >
                            <Pencil />
                            Ubah lowongan
                        </Button>
                        {vacancy.status === 'DIBUKA' ? (
                            <ConfirmAction
                                trigger={
                                    <Button type="button" variant="outline">
                                        Tutup lowongan
                                    </Button>
                                }
                                title="Tutup lowongan ini?"
                                description="Pelamar baru tidak bisa ditambahkan. Seleksi pelamar yang sudah tercatat tetap bisa dilanjutkan."
                                action={`${base}/tutup`}
                                confirmLabel="Tutup lowongan"
                                errorBag="vacancy-close"
                            />
                        ) : (
                            <ConfirmAction
                                trigger={
                                    <Button type="button" variant="outline">
                                        {vacancy.status === 'DRAF'
                                            ? 'Buka lowongan'
                                            : 'Buka lagi'}
                                    </Button>
                                }
                                title="Buka lowongan ini?"
                                description="Pelamar bisa ditambahkan ke lowongan yang dibuka."
                                action={`${base}/buka`}
                                confirmLabel="Buka lowongan"
                                destructive={false}
                                errorBag="vacancy-open"
                            />
                        )}
                        <Button asChild variant="outline">
                            <a href={`${base}/rekap`}>
                                <Download />
                                Unduh rekap .xlsx
                            </a>
                        </Button>
                        {vacancy.status === 'DRAF' && vacancy.total === 0 && (
                            <ConfirmAction
                                trigger={
                                    <Button type="button" variant="outline">
                                        <Trash2 />
                                        Hapus
                                    </Button>
                                }
                                title="Hapus lowongan ini?"
                                description="Lowongan Draf tanpa pelamar dihapus permanen."
                                action={base}
                                method="delete"
                                confirmLabel="Hapus lowongan"
                                errorBag="vacancy-delete"
                            />
                        )}
                    </div>
                )}

                {canManage && (
                    <FormDialog
                        open={editing}
                        onOpenChange={setEditing}
                        title="Ubah lowongan"
                        wide
                    >
                        <VacancyForm
                            vacancy={vacancy}
                            organizations={options.organizations}
                            positions={options.positions}
                            limits={limits}
                            onDone={() => setEditing(false)}
                        />
                    </FormDialog>
                )}

                <section className="space-y-3">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 className="text-base font-medium">
                            Pelamar ({applicants.length})
                        </h2>
                        {canManage && vacancy.status === 'DIBUKA' && (
                            <Button
                                type="button"
                                onClick={() => setAdding(true)}
                                className="w-full sm:w-auto"
                            >
                                <Plus />
                                Tambah pelamar
                            </Button>
                        )}
                    </div>

                    {canManage && (
                        <FormDialog
                            open={adding}
                            onOpenChange={setAdding}
                            title="Tambah pelamar"
                            description="Masukkan data dari berkas lamaran yang diterima (WA, email, atau datang langsung)."
                            wide
                        >
                            <ApplicantForm
                                action={`${base}/pelamar`}
                                options={options}
                                limits={limits}
                                onDone={() => setAdding(false)}
                            />
                        </FormDialog>
                    )}

                    {applicants.length === 0 ? (
                        <div className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                            {vacancy.status === 'DIBUKA'
                                ? 'Belum ada pelamar.'
                                : vacancy.status === 'DRAF'
                                  ? 'Buka lowongan dulu untuk mulai mencatat pelamar.'
                                  : 'Lowongan ini ditutup tanpa pelamar.'}
                        </div>
                    ) : (
                        STAGE_ORDER.map((stage) => {
                            const rows = applicants.filter(
                                (applicant) => applicant.stage === stage,
                            );

                            if (rows.length === 0) {
                                return null;
                            }

                            return (
                                <div key={stage} className="space-y-2">
                                    <h3 className="text-sm font-medium text-muted-foreground">
                                        {rows[0].stage_label} ({rows.length})
                                    </h3>
                                    <ul className="divide-y rounded-xl border">
                                        {rows.map((applicant) => (
                                            <ApplicantRow
                                                key={applicant.id}
                                                applicant={applicant}
                                                base={base}
                                                canManage={canManage}
                                                canHire={canHire}
                                                options={options}
                                                limits={limits}
                                            />
                                        ))}
                                    </ul>
                                </div>
                            );
                        })
                    )}
                </section>
            </div>
        </>
    );
}

function ApplicantRow({
    applicant,
    base,
    canManage,
    canHire,
    options,
    limits,
}: {
    applicant: Applicant;
    base: string;
    canManage: boolean;
    canHire: boolean;
    options: Props['options'];
    limits: RecruitmentLimits;
}) {
    const [editing, setEditing] = useState(false);
    const url = `${base}/pelamar/${applicant.id}`;
    const final =
        applicant.stage === 'DITERIMA' || applicant.stage === 'TIDAK_LOLOS';
    const details = [
        applicant.gender === 'L' ? 'Laki-laki' : 'Perempuan',
        [applicant.education_label, applicant.education_major]
            .filter(Boolean)
            .join(' '),
        applicant.phone,
        applicant.email,
    ].filter(Boolean);

    return (
        <li className="space-y-2 p-4 text-sm">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0 space-y-0.5">
                    <p className="font-medium break-words">{applicant.name}</p>
                    <p className="text-xs break-words text-muted-foreground">
                        {details.join(' · ')}
                    </p>
                    {applicant.note && (
                        <p className="text-xs break-words text-muted-foreground">
                            Catatan: {applicant.note}
                        </p>
                    )}
                    <p className="text-xs text-muted-foreground">
                        Ditambahkan {formatRecruitmentDate(applicant.added_on)}
                    </p>
                </div>
                <Badge
                    variant={
                        applicant.stage === 'DITERIMA'
                            ? 'default'
                            : applicant.stage === 'TIDAK_LOLOS'
                              ? 'secondary'
                              : 'outline'
                    }
                >
                    {applicant.stage_label}
                </Badge>
            </div>

            <div className="grid grid-cols-[repeat(auto-fit,minmax(9rem,1fr))] gap-2 sm:flex sm:flex-wrap">
                {applicant.file_name && (
                    <Button asChild size="sm" variant="outline">
                        <a href={`${url}/unduh`}>
                            <Download />
                            Berkas
                        </a>
                    </Button>
                )}
                {canManage && applicant.stage === 'BERKAS' && (
                    <StepButton
                        url={url}
                        action="LOLOS_BERKAS"
                        label="Lolos berkas"
                        icon={<Check />}
                        title={`${applicant.name} lolos seleksi berkas?`}
                        description="Pelamar pindah ke tahap Tes/wawancara."
                        limits={limits}
                    />
                )}
                {canManage && applicant.stage === 'TES' && (
                    <StepButton
                        url={url}
                        action="DITERIMA"
                        label="Diterima"
                        icon={<Check />}
                        title={`Terima ${applicant.name}?`}
                        description="Pelamar dinyatakan diterima. Tombol untuk menjadikannya pegawai menyusul di tahap berikutnya."
                        limits={limits}
                    />
                )}
                {canManage && !final && (
                    <StepButton
                        url={url}
                        action="TIDAK_LOLOS"
                        label="Tidak lolos"
                        icon={<X />}
                        title={`${applicant.name} tidak lolos?`}
                        description="Seleksi pelamar ini selesai."
                        limits={limits}
                        reason
                        destructive
                    />
                )}
                {applicant.stage === 'DITERIMA' &&
                    (applicant.employee_id ? (
                        <Button asChild size="sm" variant="outline">
                            <Link href={`/pegawai/${applicant.employee_id}`}>
                                <UserCheck />
                                Lihat pegawai
                            </Link>
                        </Button>
                    ) : (
                        canHire && (
                            <Button asChild size="sm">
                                <Link
                                    href={`/pegawai/tambah?pelamar=${applicant.id}`}
                                >
                                    <UserPlus />
                                    Jadikan pegawai
                                </Link>
                            </Button>
                        )
                    ))}
                {canManage && final && !applicant.employee_id && (
                    <StepButton
                        url={url}
                        action="KEMBALI"
                        label="Kembalikan"
                        icon={<RotateCcw />}
                        title={`Kembalikan ${applicant.name} ke Tes/wawancara?`}
                        description="Untuk memperbaiki hasil yang salah dicatat."
                        limits={limits}
                        reason
                    />
                )}
                {(canManage ? !final : applicant.stage === 'TES') && (
                    <StepButton
                        url={url}
                        action="CATATAN"
                        label={
                            applicant.stage === 'TES'
                                ? 'Catatan wawancara'
                                : 'Catatan'
                        }
                        icon={<MessageSquarePlus />}
                        title={`Catatan untuk ${applicant.name}`}
                        description="Catatan & nilai tidak memindahkan tahap."
                        limits={limits}
                        noteRequired
                    />
                )}
                {canManage && !final && (
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() => setEditing(true)}
                    >
                        <Pencil />
                        Ubah data
                    </Button>
                )}
            </div>

            {applicant.steps.length > 0 && (
                <details className="text-xs">
                    <summary className="cursor-pointer text-muted-foreground">
                        Riwayat seleksi ({applicant.steps.length})
                    </summary>
                    <ul className="mt-2 space-y-1.5 border-l pl-3">
                        {applicant.steps.map((step, index) => (
                            <li key={index} className="space-y-0.5">
                                <p>
                                    <span className="font-medium">
                                        {step.action_label}
                                    </span>
                                    {step.score !== null &&
                                        ` · nilai ${step.score}`}{' '}
                                    <span className="text-muted-foreground">
                                        ({step.stage_label},{' '}
                                        {formatRecruitmentDate(step.at)}
                                        {step.by && `, ${step.by}`})
                                    </span>
                                </p>
                                {step.note && (
                                    <p className="break-words text-muted-foreground">
                                        {step.note}
                                    </p>
                                )}
                            </li>
                        ))}
                    </ul>
                </details>
            )}

            {canManage && (
                <FormDialog
                    open={editing}
                    onOpenChange={setEditing}
                    title={`Ubah data ${applicant.name}`}
                    wide
                >
                    <ApplicantForm
                        action={url}
                        applicant={applicant}
                        options={options}
                        limits={limits}
                        onDone={() => setEditing(false)}
                    />
                </FormDialog>
            )}
        </li>
    );
}

function StepButton({
    url,
    action,
    label,
    icon,
    title,
    description,
    limits,
    reason = false,
    noteRequired = false,
    destructive = false,
}: {
    url: string;
    action: string;
    label: string;
    icon: ReactNode;
    title: string;
    description: string;
    limits: RecruitmentLimits;
    reason?: boolean;
    noteRequired?: boolean;
    destructive?: boolean;
}) {
    const [open, setOpen] = useState(false);
    const id = `${url}-${action}`.replace(/[^a-zA-Z0-9-]/g, '-');

    return (
        <>
            <Button
                type="button"
                size="sm"
                variant="outline"
                onClick={() => setOpen(true)}
            >
                {icon}
                {label}
            </Button>
            <FormDialog
                open={open}
                onOpenChange={setOpen}
                title={title}
                description={description}
            >
                <Form
                    action={`${url}/tahap`}
                    method="post"
                    errorBag={id}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    className="space-y-3"
                >
                    {({ processing, errors }) => (
                        <>
                            <input type="hidden" name="action" value={action} />
                            {!reason && (
                                <div className="grid gap-1.5">
                                    <Label htmlFor={`${id}-score`}>
                                        Nilai 0–100 (opsional)
                                    </Label>
                                    <Input
                                        id={`${id}-score`}
                                        name="score"
                                        type="number"
                                        inputMode="numeric"
                                        min={0}
                                        max={100}
                                        step={1}
                                    />
                                    <InputError message={errors.score} />
                                </div>
                            )}
                            <div className="grid gap-1.5">
                                <Label htmlFor={`${id}-note`}>
                                    {reason
                                        ? 'Alasan'
                                        : noteRequired
                                          ? 'Catatan'
                                          : 'Catatan (opsional)'}
                                </Label>
                                <textarea
                                    id={`${id}-note`}
                                    name="note"
                                    className={textareaClass}
                                    maxLength={
                                        reason ? limits.reason : limits.note
                                    }
                                    required={reason || noteRequired}
                                />
                                <InputError message={errors.note} />
                            </div>
                            <InputError
                                message={
                                    (errors as Record<string, string>).form
                                }
                            />
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    type="submit"
                                    variant={
                                        destructive ? 'destructive' : 'default'
                                    }
                                    disabled={processing}
                                >
                                    {processing ? 'Menyimpan…' : label}
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setOpen(false)}
                                >
                                    Batal
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </FormDialog>
        </>
    );
}

function ApplicantForm({
    action,
    applicant,
    options,
    limits,
    onDone,
}: {
    action: string;
    applicant?: Applicant;
    options: Props['options'];
    limits: RecruitmentLimits;
    onDone: () => void;
}) {
    const key = applicant?.id ?? 'new';

    return (
        <Form
            action={action}
            method="post"
            errorBag={`applicant-${key}`}
            options={{ preserveScroll: true }}
            onSuccess={onDone}
            className="space-y-3"
        >
            {({ processing, errors }) => (
                <>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="grid gap-1.5 sm:col-span-2">
                            <Label htmlFor={`applicant-name-${key}`}>
                                Nama
                            </Label>
                            <Input
                                id={`applicant-name-${key}`}
                                name="name"
                                defaultValue={applicant?.name ?? ''}
                                maxLength={limits.name}
                                required
                            />
                            <InputError message={errors.name} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor={`applicant-gender-${key}`}>
                                Jenis kelamin
                            </Label>
                            <select
                                id={`applicant-gender-${key}`}
                                name="gender"
                                className={selectClass}
                                defaultValue={applicant?.gender ?? ''}
                                required
                            >
                                <option value="">Pilih</option>
                                {options.genders.map((item) => (
                                    <option key={item.value} value={item.value}>
                                        {item.label}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.gender} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor={`applicant-phone-${key}`}>
                                No. HP (opsional)
                            </Label>
                            <Input
                                id={`applicant-phone-${key}`}
                                name="phone"
                                inputMode="tel"
                                defaultValue={applicant?.phone ?? ''}
                                placeholder="0812…"
                            />
                            <InputError message={errors.phone} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor={`applicant-email-${key}`}>
                                Email (opsional)
                            </Label>
                            <Input
                                id={`applicant-email-${key}`}
                                name="email"
                                type="email"
                                defaultValue={applicant?.email ?? ''}
                                maxLength={limits.email}
                            />
                            <InputError message={errors.email} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor={`applicant-education-${key}`}>
                                Pendidikan terakhir
                            </Label>
                            <select
                                id={`applicant-education-${key}`}
                                name="education_level"
                                className={selectClass}
                                defaultValue={applicant?.education_level ?? ''}
                            >
                                <option value="">(belum diisi)</option>
                                {options.educationLevels.map((item) => (
                                    <option key={item.value} value={item.value}>
                                        {item.label}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.education_level} />
                        </div>
                        <div className="grid gap-1.5 sm:col-span-2">
                            <Label htmlFor={`applicant-major-${key}`}>
                                Jurusan (opsional)
                            </Label>
                            <Input
                                id={`applicant-major-${key}`}
                                name="education_major"
                                defaultValue={applicant?.education_major ?? ''}
                                maxLength={limits.major}
                            />
                            <InputError message={errors.education_major} />
                        </div>
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor={`applicant-note-${key}`}>
                            Catatan (opsional)
                        </Label>
                        <textarea
                            id={`applicant-note-${key}`}
                            name="note"
                            className={textareaClass}
                            maxLength={limits.note}
                            defaultValue={applicant?.note ?? ''}
                        />
                        <InputError message={errors.note} />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor={`applicant-file-${key}`}>
                            {applicant?.file_name
                                ? `Ganti berkas lamaran (sekarang: ${applicant.file_name})`
                                : 'Berkas lamaran (opsional)'}
                        </Label>
                        <Input
                            id={`applicant-file-${key}`}
                            name="file"
                            type="file"
                            accept=".pdf,.jpg,.jpeg,.png"
                        />
                        <p className="text-xs text-muted-foreground">
                            CV & berkas digabung jadi satu file PDF/JPG/PNG,
                            maks. {limits.fileMaxMb} MB.
                        </p>
                        <InputError message={errors.file} />
                    </div>
                    <InputError
                        message={(errors as Record<string, string>).form}
                    />
                    <div className="flex flex-wrap gap-2">
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Menyimpan…' : 'Simpan pelamar'}
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
