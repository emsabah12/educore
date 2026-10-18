import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Lock, UserPlus } from 'lucide-react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

type Enrollment = {
    id: string;
    organization: string;
    organization_code: string;
    student_number: string | null;
    entry_type: string;
    start_date: string;
    end_date: string | null;
    end_reason: string | null;
    end_note: string | null;
    open: boolean;
};

type Guardian = {
    name: string;
    relation: string;
    gender: string | null;
    phone: string | null;
    is_primary: boolean;
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
    guardians: Guardian[] | null;
    enrollments: { current: Enrollment[]; history: Enrollment[] };
};

type Props = {
    student: Student;
    canCreate: boolean;
};

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
 * data pribadi & orang tua/wali hanya untuk pemilik izin lihat data pribadi. Aksi menyusul (AC-1c-b).
 */
export default function StudentDetail({ student, canCreate }: Props) {
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
                        <EnrollmentList items={student.enrollments.current} />
                    )}
                </Section>

                <Section title="Orang tua/wali">
                    {student.guardians === null ? (
                        lockedNote
                    ) : student.guardians.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Belum ada orang tua/wali yang dicatat.
                        </p>
                    ) : (
                        <ul className="divide-y">
                            {student.guardians.map((guardian, index) => (
                                <li
                                    key={index}
                                    className="flex flex-wrap items-start justify-between gap-2 py-3"
                                >
                                    <div className="min-w-0 space-y-0.5">
                                        <p className="font-medium break-words">
                                            {guardian.name}
                                        </p>
                                        <p className="text-sm text-muted-foreground">
                                            {[
                                                guardian.relation,
                                                guardian.gender,
                                            ]
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
                                </li>
                            ))}
                        </ul>
                    )}
                </Section>

                {student.enrollments.history.length > 0 && (
                    <Section title="Riwayat pendaftaran">
                        <EnrollmentList items={student.enrollments.history} />
                    </Section>
                )}
            </div>
        </>
    );
}

function EnrollmentList({ items }: { items: Enrollment[] }) {
    return (
        <ul className="divide-y">
            {items.map((enrollment) => (
                <li key={enrollment.id} className="space-y-1 py-3">
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
                            {enrollment.end_note && ` — ${enrollment.end_note}`}
                        </p>
                    )}
                </li>
            ))}
        </ul>
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
