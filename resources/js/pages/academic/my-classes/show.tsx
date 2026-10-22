import { Head, Link } from '@inertiajs/react';
import {
    ArrowLeft,
    ChartColumn,
    ClipboardCheck,
    Download,
    NotebookPen,
    Phone,
    Sheet,
} from 'lucide-react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

type Student = {
    id: string;
    name: string;
    gender: string | null;
    nisn: string | null;
    student_number: string | null;
    contact: { name: string; relation: string; phone: string | null } | null;
};

type Props = {
    class: {
        id: string;
        name: string;
        grade: number;
        organization: string;
        year: string;
        homeroom: boolean;
        subjects: string[];
        taught: { id: string; name: string }[];
        lesson_mode: boolean;
    };
    students: Student[];
};

/**
 * Siswa di kelas yang dipegang (PRD-002 §16 AC-3d). Kontak utama orang tua/wali hanya untuk wali
 * kelas; data pribadi lain tidak dikirim server.
 */
export default function MyClassDetail({ class: item, students }: Props) {
    const male = students.filter((student) => student.gender === 'L').length;
    const female = students.filter((student) => student.gender === 'P').length;

    return (
        <>
            <Head title={`Kelas ${item.name}`} />

            <div className="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-4 p-4">
                <div>
                    <Link
                        href="/kelas-saya"
                        className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                    >
                        <ArrowLeft className="size-4" />
                        Kelas saya
                    </Link>
                </div>

                <div className="space-y-2">
                    <Heading
                        title={`Kelas ${item.name}`}
                        description={`${item.organization} · Tingkat ${item.grade} · ${item.year}`}
                    />
                    <div className="-mt-6 flex flex-wrap gap-1.5">
                        {item.homeroom && <Badge>Wali kelas</Badge>}
                        {item.subjects.map((subject) => (
                            <Badge key={subject} variant="secondary">
                                {subject}
                            </Badge>
                        ))}
                        <Badge variant="outline">
                            {students.length} siswa (L {male} · P {female})
                        </Badge>
                    </div>
                </div>

                <div className="grid grid-cols-[repeat(auto-fit,minmax(10rem,1fr))] gap-2 sm:flex">
                    {item.homeroom && !item.lesson_mode && (
                        <Button asChild>
                            <Link href={`/kelas-saya/${item.id}/presensi`}>
                                <ClipboardCheck />
                                Isi presensi
                            </Link>
                        </Button>
                    )}
                    <Button variant="outline" asChild>
                        <Link href={`/kelas-saya/${item.id}/rekap-presensi`}>
                            <ChartColumn />
                            Rekap presensi
                        </Link>
                    </Button>
                    {item.homeroom && (
                        <Button variant="outline" asChild>
                            <Link href={`/kelas-saya/${item.id}/leger`}>
                                <Sheet />
                                Leger nilai
                            </Link>
                        </Button>
                    )}
                    {item.homeroom && students.length > 0 && (
                        <Button variant="outline" asChild>
                            {/* Unduhan file: tautan biasa, bukan navigasi Inertia. */}
                            <a href={`/kelas-saya/${item.id}/unduh`}>
                                <Download />
                                Unduh daftar (.xlsx)
                            </a>
                        </Button>
                    )}
                </div>
                {item.taught.length > 0 && (
                    <section className="space-y-2 rounded-xl border p-4">
                        <h2 className="text-sm font-medium">
                            Nilai mapel yang saya ampu
                        </h2>
                        <div className="grid grid-cols-[repeat(auto-fit,minmax(10rem,1fr))] gap-2">
                            {item.taught.map((subject) => (
                                <Button
                                    key={subject.id}
                                    variant="outline"
                                    asChild
                                >
                                    <Link
                                        href={`/kelas-saya/mapel/${subject.id}/nilai`}
                                    >
                                        <NotebookPen />
                                        {subject.name}
                                    </Link>
                                </Button>
                            ))}
                        </div>
                    </section>
                )}
                {item.lesson_mode && (
                    <p className="text-sm text-muted-foreground">
                        Lembaga ini memakai presensi per pertemuan: isi presensi
                        dari Jadwal mengajar di halaman Kelas saya.
                    </p>
                )}

                <section className="rounded-xl border">
                    {students.length === 0 ? (
                        <p className="p-6 text-center text-sm text-muted-foreground">
                            Belum ada siswa di kelas ini.
                        </p>
                    ) : (
                        <ul className="divide-y">
                            {students.map((student, index) => (
                                <li
                                    key={student.id}
                                    className="grid gap-1 px-4 py-3 sm:grid-cols-[2fr_2fr] sm:items-center sm:gap-3"
                                >
                                    <div className="min-w-0">
                                        <p className="font-medium break-words">
                                            <span className="mr-1 text-muted-foreground tabular-nums">
                                                {index + 1}.
                                            </span>
                                            {student.name}
                                        </p>
                                        <p className="text-sm text-muted-foreground">
                                            {[
                                                student.gender,
                                                student.nisn
                                                    ? `NISN ${student.nisn}`
                                                    : null,
                                                student.student_number
                                                    ? `No. induk ${student.student_number}`
                                                    : null,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </p>
                                    </div>
                                    {item.homeroom && (
                                        <div className="text-sm">
                                            {student.contact ? (
                                                <>
                                                    <p className="break-words">
                                                        {student.contact.name}
                                                        {student.contact
                                                            .relation && (
                                                            <span className="text-muted-foreground">
                                                                {' '}
                                                                (
                                                                {
                                                                    student
                                                                        .contact
                                                                        .relation
                                                                }
                                                                )
                                                            </span>
                                                        )}
                                                    </p>
                                                    {student.contact.phone ? (
                                                        <a
                                                            href={`tel:${student.contact.phone}`}
                                                            className="inline-flex items-center gap-1 text-primary hover:underline"
                                                        >
                                                            <Phone className="size-3.5" />
                                                            {
                                                                student.contact
                                                                    .phone
                                                            }
                                                        </a>
                                                    ) : (
                                                        <p className="text-muted-foreground">
                                                            Tanpa nomor HP
                                                        </p>
                                                    )}
                                                </>
                                            ) : (
                                                <p className="text-muted-foreground">
                                                    Kontak utama belum ada
                                                </p>
                                            )}
                                        </div>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                {item.homeroom && (
                    <p className="text-xs text-muted-foreground">
                        Kontak utama orang tua/wali hanya terlihat oleh wali
                        kelas. Gunakan untuk keperluan sekolah saja.
                    </p>
                )}
            </div>
        </>
    );
}
