import { Head, Link } from '@inertiajs/react';
import {
    ArrowLeft,
    Building2,
    DoorClosed,
    Link2Off,
    Lock,
    Pencil,
    Plus,
    UserPen,
    UserPlus,
} from 'lucide-react';
import { useState } from 'react';
import type { GuardianRow } from '@/components/academic/guardian-actions';
import {
    AddGuardianForm,
    EditGuardianForm,
    MakePrimaryButton,
    UnlinkGuardianForm,
} from '@/components/academic/guardian-actions';
import type { StudentManageData } from '@/components/academic/student-actions';
import {
    CloseEnrollmentForm,
    EditEnrollmentForm,
    EnrollForm,
    ProfileForm,
} from '@/components/academic/student-actions';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

type Enrollment = {
    id: string;
    organization_id: string;
    organization: string;
    organization_code: string;
    student_number: string | null;
    entry_type: string;
    entry_type_code: string;
    start_date: string;
    end_date: string | null;
    end_reason: string | null;
    end_note: string | null;
    open: boolean;
    can_manage: boolean;
};

type Student = {
    id: string;
    name: string;
    gender: string | null;
    nisn: string | null;
    active: boolean;
    personal: {
        birth_place: string | null;
        birth_date: string | null;
        phone: string | null;
        address: string | null;
    } | null;
    guardians: GuardianRow[] | null;
    enrollments: { current: Enrollment[]; history: Enrollment[] };
};

type Props = {
    student: Student;
    canCreate: boolean;
    manage: StudentManageData | null;
};

/** Aksi yang sedang terbuka: satu form pada satu waktu. */
type OpenAction =
    | { kind: 'profile' }
    | { kind: 'enroll' }
    | { kind: 'editEnrollment'; id: string }
    | { kind: 'closeEnrollment'; id: string }
    | { kind: 'addGuardian' }
    | { kind: 'editGuardian'; id: string }
    | { kind: 'unlinkGuardian'; id: string };

const dateFormat = new Intl.DateTimeFormat('id-ID', { dateStyle: 'long' });

/** "2026-07-13" → "13 Juli 2026" tanpa geser zona waktu. */
function formatDate(value: string | null): string {
    if (!value) {
        return '–';
    }

    const [year, month, day] = value.slice(0, 10).split('-').map(Number);

    return dateFormat.format(new Date(year, month - 1, day));
}

/**
 * Detail siswa (PRD-002 §6, AC-1c-a). Semua pendaftaran siswa tampil, termasuk di lembaga lain;
 * data pribadi & orang tua/wali hanya untuk pemilik izin lihat data pribadi.
 * Aksi (AC-1c-b1) hanya tampil bagi yang berhak; server tetap memeriksa ulang setiap aksi.
 */
export default function StudentDetail({ student, canCreate, manage }: Props) {
    const [action, setAction] = useState<OpenAction | null>(null);
    const close = () => setAction(null);
    const toggle = (next: OpenAction) =>
        setAction((current) =>
            current &&
            current.kind === next.kind &&
            ('id' in current ? current.id : '') ===
                ('id' in next ? next.id : '')
                ? null
                : next,
        );
    const canEnroll = manage !== null && manage.enrollTargets.length > 0;

    const lockedNote = (
        <p className="flex items-center gap-2 text-sm text-muted-foreground">
            <Lock className="size-4 shrink-0" />
            Hanya untuk pemilik izin lihat data pribadi siswa.
        </p>
    );

    return (
        <>
            <Head title={student.name} />

            <div className="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <Link
                        href="/siswa"
                        className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                    >
                        <ArrowLeft className="size-4" />
                        Daftar siswa
                    </Link>
                    {canCreate && (
                        <Button variant="outline" size="sm" asChild>
                            <Link href="/siswa/tambah">
                                <UserPlus />
                                Tambah siswa lain
                            </Link>
                        </Button>
                    )}
                </div>

                <div className="space-y-2">
                    <Heading
                        title={student.name}
                        description={
                            student.nisn
                                ? `NISN ${student.nisn}`
                                : 'NISN belum diisi'
                        }
                    />
                    <div className="-mt-6 flex flex-wrap gap-1.5">
                        <Badge variant={student.active ? 'default' : 'outline'}>
                            {student.active ? 'Aktif' : 'Tidak aktif'}
                        </Badge>
                        {student.gender && (
                            <Badge variant="secondary">{student.gender}</Badge>
                        )}
                    </div>
                </div>

                {manage && (manage.profile || canEnroll) && (
                    <section aria-label="Kelola siswa" className="space-y-3">
                        {/* Grid otomatis: tombol turun ke baris berikutnya di layar sempit. */}
                        <div className="grid grid-cols-[repeat(auto-fit,minmax(12rem,1fr))] gap-2">
                            {manage.profile && (
                                <ActionButton
                                    active={action?.kind === 'profile'}
                                    onClick={() => toggle({ kind: 'profile' })}
                                    icon={<UserPen />}
                                    label="Ubah data diri"
                                />
                            )}
                            {canEnroll && (
                                <ActionButton
                                    active={action?.kind === 'enroll'}
                                    onClick={() => toggle({ kind: 'enroll' })}
                                    icon={<Building2 />}
                                    label="Daftarkan ke lembaga lain"
                                />
                            )}
                        </div>

                        {action?.kind === 'profile' && manage.profile && (
                            <ProfileForm
                                studentId={student.id}
                                manage={manage}
                                values={manage.profile}
                                onDone={close}
                            />
                        )}
                        {action?.kind === 'enroll' && (
                            <EnrollForm
                                studentId={student.id}
                                manage={manage}
                                onDone={close}
                            />
                        )}
                    </section>
                )}

                <Section title="Data pribadi">
                    {student.personal ? (
                        <dl className="grid gap-3 sm:grid-cols-2">
                            <Item
                                label="Tempat lahir"
                                value={student.personal.birth_place}
                            />
                            <Item
                                label="Tanggal lahir"
                                value={
                                    student.personal.birth_date
                                        ? formatDate(
                                              student.personal.birth_date,
                                          )
                                        : null
                                }
                            />
                            <Item
                                label="Nomor HP"
                                value={student.personal.phone}
                                mono
                            />
                            <Item
                                label="Alamat"
                                value={student.personal.address}
                            />
                        </dl>
                    ) : (
                        lockedNote
                    )}
                </Section>

                <Section title="Pendaftaran berjalan">
                    {student.enrollments.current.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Tidak ada pendaftaran yang masih berjalan.
                        </p>
                    ) : (
                        <EnrollmentList
                            items={student.enrollments.current}
                            studentId={student.id}
                            manage={manage}
                            action={action}
                            onToggle={toggle}
                            onDone={close}
                        />
                    )}
                </Section>

                <Section title="Orang tua/wali">
                    {student.guardians === null ? (
                        lockedNote
                    ) : (
                        <GuardianList
                            guardians={student.guardians}
                            studentId={student.id}
                            manage={manage?.guardians ? manage : null}
                            action={action}
                            onToggle={toggle}
                            onDone={close}
                        />
                    )}
                </Section>

                {student.enrollments.history.length > 0 && (
                    <Section title="Riwayat pendaftaran">
                        <EnrollmentList
                            items={student.enrollments.history}
                            studentId={student.id}
                            manage={manage}
                            action={action}
                            onToggle={toggle}
                            onDone={close}
                        />
                    </Section>
                )}
            </div>
        </>
    );
}

function EnrollmentList({
    items,
    studentId,
    manage,
    action,
    onToggle,
    onDone,
}: {
    items: Enrollment[];
    studentId: string;
    manage: StudentManageData | null;
    action: OpenAction | null;
    onToggle: (next: OpenAction) => void;
    onDone: () => void;
}) {
    const isOpen = (kind: OpenAction['kind'], id: string) =>
        action !== null &&
        action.kind === kind &&
        'id' in action &&
        action.id === id;

    return (
        <ul className="divide-y">
            {items.map((enrollment) => (
                <li key={enrollment.id} className="space-y-2 py-3">
                    <div className="flex flex-wrap items-start justify-between gap-2">
                        <div className="min-w-0 space-y-1">
                            <div className="flex flex-wrap items-center gap-1.5">
                                <span className="font-medium">
                                    {enrollment.organization}
                                </span>
                                <span className="font-mono text-xs text-muted-foreground">
                                    {enrollment.organization_code}
                                </span>
                                {enrollment.open && <Badge>Berjalan</Badge>}
                            </div>
                            <p className="text-sm text-muted-foreground">
                                {enrollment.student_number
                                    ? `Nomor induk ${enrollment.student_number} · `
                                    : ''}
                                {enrollment.entry_type} ·{' '}
                                {formatDate(enrollment.start_date)} –{' '}
                                {enrollment.end_date
                                    ? formatDate(enrollment.end_date)
                                    : 'sekarang'}
                            </p>
                            {enrollment.end_reason && (
                                <p className="text-sm">
                                    Ditutup: {enrollment.end_reason}
                                    {enrollment.end_note &&
                                        ` — ${enrollment.end_note}`}
                                </p>
                            )}
                        </div>
                        {manage && enrollment.can_manage && (
                            <div className="grid w-full grid-cols-[repeat(auto-fit,minmax(8rem,1fr))] gap-2 sm:w-auto sm:grid-flow-col sm:grid-cols-none">
                                <Button
                                    type="button"
                                    size="sm"
                                    variant={
                                        isOpen('editEnrollment', enrollment.id)
                                            ? 'default'
                                            : 'outline'
                                    }
                                    onClick={() =>
                                        onToggle({
                                            kind: 'editEnrollment',
                                            id: enrollment.id,
                                        })
                                    }
                                >
                                    <Pencil />
                                    Ubah
                                </Button>
                                {enrollment.open && (
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant={
                                            isOpen(
                                                'closeEnrollment',
                                                enrollment.id,
                                            )
                                                ? 'destructive'
                                                : 'outline'
                                        }
                                        onClick={() =>
                                            onToggle({
                                                kind: 'closeEnrollment',
                                                id: enrollment.id,
                                            })
                                        }
                                    >
                                        <DoorClosed />
                                        Tutup
                                    </Button>
                                )}
                            </div>
                        )}
                    </div>

                    {manage && isOpen('editEnrollment', enrollment.id) && (
                        <EditEnrollmentForm
                            studentId={studentId}
                            enrollment={enrollment}
                            manage={manage}
                            onDone={onDone}
                        />
                    )}
                    {manage && isOpen('closeEnrollment', enrollment.id) && (
                        <CloseEnrollmentForm
                            studentId={studentId}
                            enrollment={enrollment}
                            manage={manage}
                            onDone={onDone}
                        />
                    )}
                </li>
            ))}
        </ul>
    );
}

function GuardianList({
    guardians,
    studentId,
    manage,
    action,
    onToggle,
    onDone,
}: {
    guardians: GuardianRow[];
    studentId: string;
    manage: StudentManageData | null;
    action: OpenAction | null;
    onToggle: (next: OpenAction) => void;
    onDone: () => void;
}) {
    const isOpen = (kind: OpenAction['kind'], id: string) =>
        action !== null &&
        action.kind === kind &&
        'id' in action &&
        action.id === id;
    const canAdd = manage !== null && guardians.length < manage.maxGuardians;

    return (
        <div className="space-y-3">
            {guardians.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    Belum ada orang tua/wali yang dicatat.
                </p>
            ) : (
                <ul className="divide-y">
                    {guardians.map((guardian) => (
                        <li key={guardian.id} className="space-y-2 py-3">
                            <div className="flex flex-wrap items-start justify-between gap-2">
                                <div className="min-w-0 space-y-0.5">
                                    <p className="font-medium break-words">
                                        {guardian.name}
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                        {[guardian.relation, guardian.gender]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </p>
                                </div>
                                <div className="flex flex-wrap items-center gap-1.5">
                                    {guardian.phone && (
                                        <span className="font-mono text-sm">
                                            {guardian.phone}
                                        </span>
                                    )}
                                    {guardian.is_primary && (
                                        <Badge>Kontak utama</Badge>
                                    )}
                                </div>
                            </div>

                            {manage && (
                                <div className="grid grid-cols-[repeat(auto-fit,minmax(10rem,1fr))] gap-2">
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant={
                                            isOpen('editGuardian', guardian.id)
                                                ? 'default'
                                                : 'outline'
                                        }
                                        onClick={() =>
                                            onToggle({
                                                kind: 'editGuardian',
                                                id: guardian.id,
                                            })
                                        }
                                    >
                                        <Pencil />
                                        Ubah
                                    </Button>
                                    {!guardian.is_primary && (
                                        <MakePrimaryButton
                                            studentId={studentId}
                                            guardian={guardian}
                                        />
                                    )}
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant={
                                            isOpen(
                                                'unlinkGuardian',
                                                guardian.id,
                                            )
                                                ? 'destructive'
                                                : 'outline'
                                        }
                                        onClick={() =>
                                            onToggle({
                                                kind: 'unlinkGuardian',
                                                id: guardian.id,
                                            })
                                        }
                                    >
                                        <Link2Off />
                                        Lepas
                                    </Button>
                                </div>
                            )}

                            {manage && isOpen('editGuardian', guardian.id) && (
                                <EditGuardianForm
                                    studentId={studentId}
                                    guardian={guardian}
                                    manage={manage}
                                    onDone={onDone}
                                />
                            )}
                            {manage &&
                                isOpen('unlinkGuardian', guardian.id) && (
                                    <UnlinkGuardianForm
                                        studentId={studentId}
                                        guardian={guardian}
                                        others={guardians.filter(
                                            (other) => other.id !== guardian.id,
                                        )}
                                        onDone={onDone}
                                    />
                                )}
                        </li>
                    ))}
                </ul>
            )}

            {manage &&
                (canAdd ? (
                    <>
                        <Button
                            type="button"
                            variant={
                                action?.kind === 'addGuardian'
                                    ? 'default'
                                    : 'outline'
                            }
                            onClick={() => onToggle({ kind: 'addGuardian' })}
                            className="w-full sm:w-auto"
                        >
                            <Plus />
                            Tambah orang tua/wali
                        </Button>
                        {action?.kind === 'addGuardian' && (
                            <AddGuardianForm
                                studentId={studentId}
                                manage={manage}
                                onDone={onDone}
                            />
                        )}
                    </>
                ) : (
                    <p className="text-sm text-muted-foreground">
                        Sudah {manage.maxGuardians} orang tua/wali (batas
                        maksimal). Lepas salah satu untuk menambah yang lain.
                    </p>
                ))}
        </div>
    );
}

function ActionButton({
    active,
    onClick,
    icon,
    label,
}: {
    active: boolean;
    onClick: () => void;
    icon: React.ReactNode;
    label: string;
}) {
    return (
        <Button
            type="button"
            variant={active ? 'default' : 'outline'}
            onClick={onClick}
            aria-expanded={active}
        >
            {icon}
            {label}
        </Button>
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
            {children}
        </section>
    );
}

function Item({
    label,
    value,
    mono = false,
}: {
    label: string;
    value: string | null;
    mono?: boolean;
}) {
    return (
        <div className="min-w-0 space-y-0.5">
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd
                className={
                    'text-sm break-words ' + (mono && value ? 'font-mono' : '')
                }
            >
                {value ?? '–'}
            </dd>
        </div>
    );
}
