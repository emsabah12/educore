import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft, CircleAlert, Copy } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { selectClass } from '@/components/academic/student-actions';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Target = { value: string; label: string; grade: number };

type Row = {
    member_id: string;
    student_id: string;
    name: string;
    gender: string | null;
    nisn: string | null;
    student_number: string | null;
    default: string;
    next_class: string | null;
};

type Props = {
    class: {
        id: string;
        name: string;
        grade: number;
        organization: string;
    };
    year: { id: string; name: string; end_date: string };
    nextYear: {
        id: string;
        name: string;
        start_date: string;
        past: boolean;
    } | null;
    targets: Target[];
    hasNextClasses: boolean;
    rows: Row[];
    graduationDefault: string;
    canGraduate: boolean;
    today: string;
    maxStudents: number;
};

const GRADUATE = 'lulus';
const SKIP = 'lewati';

const dateFormat = new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium' });

function formatDate(value: string): string {
    const [year, month, day] = value.slice(0, 10).split('-').map(Number);

    return dateFormat.format(new Date(year, month - 1, day));
}

/**
 * Proses akhir tahun satu kelas (PRD-002 §15, AC-2): tiap siswa dipilihkan naik, tinggal kelas,
 * lulus, atau tidak diproses. Pilihan bawaan dari server; semua aturan tetap dicek server.
 */
export default function ClassYearEnd(props: Props) {
    const { class: item, year, nextYear, rows } = props;

    return (
        <>
            <Head title={`Proses akhir tahun ${item.name}`} />

            <div className="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-4 p-4">
                <div>
                    <Link
                        href={`/kelas/${item.id}`}
                        className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                    >
                        <ArrowLeft className="size-4" />
                        Kelas {item.name}
                    </Link>
                </div>

                <Heading
                    title={`Proses akhir tahun · ${item.name}`}
                    description={`${item.organization} · Tahun ajaran ${year.name} · Tingkat ${item.grade}`}
                />

                {nextYear === null ? (
                    <Notice>
                        Tahun ajaran sesudah {year.name} belum dibuat. Tambahkan
                        dulu di{' '}
                        <Link
                            href="/akademik/kalender"
                            className="font-medium underline"
                        >
                            Kalender akademik
                        </Link>
                        , lalu siapkan kelasnya di daftar kelas.
                    </Notice>
                ) : nextYear.past ? (
                    <Notice>
                        Tahun ajaran berikutnya ({nextYear.name}) sudah lewat,
                        jadi kelas ini tidak bisa diproses lagi.
                    </Notice>
                ) : rows.length === 0 ? (
                    <p className="rounded-xl border p-4 text-sm text-muted-foreground">
                        Tidak ada siswa yang masih tercatat di kelas ini.
                    </p>
                ) : (
                    <YearEndForm {...props} nextYear={nextYear} />
                )}
            </div>
        </>
    );
}

function YearEndForm({
    class: item,
    year,
    nextYear,
    targets,
    hasNextClasses,
    rows,
    graduationDefault,
    canGraduate,
    today,
}: Props & { nextYear: NonNullable<Props['nextYear']> }) {
    const [choices, setChoices] = useState<Record<string, string>>(() =>
        Object.fromEntries(rows.map((row) => [row.member_id, row.default])),
    );

    const promote = targets.filter((target) => target.grade > item.grade);
    const retain = targets.filter((target) => target.grade === item.grade);
    const gradeOf = (value: string) =>
        targets.find((target) => target.value === value)?.grade;

    const counts = { promoted: 0, retained: 0, graduated: 0, skipped: 0 };

    for (const value of Object.values(choices)) {
        if (value === GRADUATE) {
            counts.graduated++;
        } else if (value === SKIP) {
            counts.skipped++;
        } else if ((gradeOf(value) ?? item.grade) > item.grade) {
            counts.promoted++;
        } else {
            counts.retained++;
        }
    }

    const processed = counts.promoted + counts.retained + counts.graduated;

    return (
        <Form
            action={`/kelas/${item.id}/akhir-tahun`}
            method="post"
            options={{ preserveScroll: true }}
            className="space-y-4"
        >
            {({ processing, errors }) => (
                <>
                    <section className="space-y-2 rounded-xl border p-4 text-sm">
                        <p>
                            Siswa yang naik atau tinggal kelas masuk kelas tahun
                            ajaran <strong>{nextYear.name}</strong> mulai{' '}
                            {formatDate(nextYear.start_date)}. Catatan kelas{' '}
                            {item.name} tetap tersimpan sebagai riwayat.
                        </p>
                        <p>
                            Siswa yang lulus: pendaftarannya di lembaga ini
                            ditutup dengan alasan Lulus. Hanya Admin Yayasan
                            yang bisa membuka kembali pendaftarannya, jadi
                            periksa pilihannya sebelum menyimpan.
                        </p>
                        {!canGraduate && (
                            <p className="text-muted-foreground">
                                Anda tidak berhak mencatat kelulusan di lembaga
                                ini (perlu izin kelola data siswa).
                            </p>
                        )}
                    </section>

                    {!hasNextClasses && (
                        <Notice>
                            Belum ada kelas aktif di tahun ajaran{' '}
                            {nextYear.name}. Siapkan kelasnya dulu di{' '}
                            <Link
                                href="/kelas"
                                className="font-medium underline"
                            >
                                daftar kelas
                            </Link>{' '}
                            agar siswa bisa naik atau tinggal kelas.
                        </Notice>
                    )}

                    {targets.length > 0 && (
                        <section className="space-y-2 rounded-xl border p-4 text-sm">
                            <h2 className="text-base font-medium">
                                Mata pelajaran kelas tujuan
                            </h2>
                            <p className="text-muted-foreground">
                                Mata pelajaran dan guru pengampu bisa disalin
                                dari kelas tahun ini (jadwal tidak ikut). Buka
                                kelas tujuan:
                            </p>
                            <div className="grid grid-cols-[repeat(auto-fit,minmax(9rem,1fr))] gap-2">
                                {targets.map((target) => (
                                    <Button
                                        key={target.value}
                                        asChild
                                        size="sm"
                                        variant="outline"
                                    >
                                        <Link
                                            href={`/kelas/${target.value}?salin=1#mapel`}
                                        >
                                            <Copy />
                                            {target.label}
                                        </Link>
                                    </Button>
                                ))}
                            </div>
                        </section>
                    )}

                    <section className="space-y-3 rounded-xl border p-4">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <h2 className="text-base font-medium">
                                Siswa ({rows.length})
                            </h2>
                            <div className="flex flex-wrap gap-1.5">
                                <Badge variant="secondary">
                                    Naik {counts.promoted}
                                </Badge>
                                <Badge variant="secondary">
                                    Tinggal {counts.retained}
                                </Badge>
                                <Badge variant="secondary">
                                    Lulus {counts.graduated}
                                </Badge>
                                <Badge variant="outline">
                                    Tidak diproses {counts.skipped}
                                </Badge>
                            </div>
                        </div>

                        <ul className="divide-y">
                            {rows.map((row) => (
                                <li
                                    key={row.member_id}
                                    className="grid gap-2 py-3 sm:grid-cols-[1fr_16rem] sm:items-center"
                                >
                                    <div className="min-w-0 space-y-0.5">
                                        <Link
                                            href={`/siswa/${row.student_id}`}
                                            className="font-medium break-words hover:underline"
                                        >
                                            {row.name}
                                        </Link>
                                        <p className="text-sm text-muted-foreground">
                                            {[
                                                row.gender,
                                                row.nisn
                                                    ? `NISN ${row.nisn}`
                                                    : null,
                                                row.student_number
                                                    ? `No. induk ${row.student_number}`
                                                    : null,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </p>
                                        {row.next_class && (
                                            <p className="text-sm text-amber-700 dark:text-amber-300">
                                                Sudah di kelas {row.next_class}{' '}
                                                tahun ajaran {nextYear.name}.
                                            </p>
                                        )}
                                    </div>
                                    {row.next_class ? (
                                        <p className="text-sm text-muted-foreground">
                                            Tidak diproses
                                        </p>
                                    ) : (
                                        <div className="grid gap-1">
                                            <Label
                                                htmlFor={`ye-${row.member_id}`}
                                                className="sr-only"
                                            >
                                                Pilihan untuk {row.name}
                                            </Label>
                                            <select
                                                id={`ye-${row.member_id}`}
                                                name={`decisions[${row.member_id}]`}
                                                value={choices[row.member_id]}
                                                onChange={(event) =>
                                                    setChoices((current) => ({
                                                        ...current,
                                                        [row.member_id]:
                                                            event.target.value,
                                                    }))
                                                }
                                                className={selectClass}
                                            >
                                                {promote.length > 0 && (
                                                    <optgroup label="Naik kelas">
                                                        {promote.map(
                                                            (target) => (
                                                                <option
                                                                    key={
                                                                        target.value
                                                                    }
                                                                    value={
                                                                        target.value
                                                                    }
                                                                >
                                                                    Naik ke{' '}
                                                                    {
                                                                        target.label
                                                                    }
                                                                </option>
                                                            ),
                                                        )}
                                                    </optgroup>
                                                )}
                                                {retain.length > 0 && (
                                                    <optgroup label="Tinggal kelas">
                                                        {retain.map(
                                                            (target) => (
                                                                <option
                                                                    key={
                                                                        target.value
                                                                    }
                                                                    value={
                                                                        target.value
                                                                    }
                                                                >
                                                                    Tinggal di{' '}
                                                                    {
                                                                        target.label
                                                                    }
                                                                </option>
                                                            ),
                                                        )}
                                                    </optgroup>
                                                )}
                                                {canGraduate && (
                                                    <option value={GRADUATE}>
                                                        Lulus
                                                    </option>
                                                )}
                                                <option value={SKIP}>
                                                    Tidak diproses
                                                </option>
                                            </select>
                                        </div>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </section>

                    {counts.graduated > 0 && (
                        <div className="grid max-w-xs gap-1.5">
                            <Label htmlFor="ye-graduation-date">
                                Tanggal lulus
                            </Label>
                            <Input
                                id="ye-graduation-date"
                                name="graduation_date"
                                type="date"
                                max={today}
                                defaultValue={graduationDefault}
                                required
                            />
                            <p className="text-xs text-muted-foreground">
                                Berlaku untuk semua siswa yang lulus. Bawaan:
                                akhir tahun ajaran {year.name}.
                            </p>
                            <InputError message={errors.graduation_date} />
                        </div>
                    )}

                    <InputError message={errors.decisions ?? errors.form} />

                    <div className="grid grid-cols-[repeat(auto-fit,minmax(12rem,1fr))] gap-2 sm:flex">
                        <Button
                            type="submit"
                            disabled={processing || processed === 0}
                        >
                            {processing
                                ? 'Menyimpan…'
                                : `Proses ${processed} siswa`}
                        </Button>
                        <Button variant="outline" asChild>
                            <Link href={`/kelas/${item.id}`}>Batal</Link>
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

function Notice({ children }: { children: ReactNode }) {
    return (
        <div className="flex gap-2 rounded-md border border-amber-500/50 bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
            <CircleAlert className="mt-0.5 size-4 shrink-0" />
            <p>{children}</p>
        </div>
    );
}
