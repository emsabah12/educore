import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, BookOpenCheck, CalendarCheck } from 'lucide-react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';

type Lesson = {
    day: number;
    start: string;
    end: string;
    subject: string;
    teacher: string | null;
};

type ClassInfo = {
    id: string;
    name: string;
    organization: string;
    year: string;
    homeroom: string | null;
    schedule: Lesson[];
};

type Period = { name: string; start_date: string; end_date: string };

type CalendarInfo = {
    organization: string;
    year: Period | null;
    terms: Period[];
    weekly_off_days: string[];
    holidays: Period[];
};

type Props = {
    student: {
        id: string;
        name: string;
        gender: string | null;
        nisn: string | null;
        birth_place: string | null;
        birth_date: string | null;
        relation: string;
    };
    enrollments: {
        organization: string;
        student_number: string | null;
        start_date: string;
    }[];
    classes: ClassInfo[];
    calendars: CalendarInfo[];
    siblings: { id: string; name: string }[];
    days: { value: number; label: string }[];
    today: string;
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

function formatRange(start: string, end: string): string {
    return start === end
        ? formatDate(start)
        : `${formatDate(start)} – ${formatDate(end)}`;
}

/**
 * Detail anak (PRD-002 §21.2, AC-7a): profil ringkas, lembaga, kelas & wali kelas, jadwal pelajaran,
 * kalender akademik. Hanya baca; akses dari tautan orang tua/wali.
 */
export default function MyChild({
    student,
    enrollments,
    classes,
    calendars,
    siblings,
    days,
    today,
}: Props) {
    const dayLabel = (value: number) =>
        days.find((day) => day.value === value)?.label ?? String(value);

    return (
        <>
            <Head title={student.name} />

            <div className="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <Button asChild variant="ghost" size="sm">
                        <Link href="/anak-saya">
                            <ArrowLeft />
                            Anak saya
                        </Link>
                    </Button>

                    {siblings.length > 1 && (
                        <label className="flex min-w-0 items-center gap-2 text-sm">
                            <span className="text-muted-foreground">Anak</span>
                            <select
                                value={student.id}
                                onChange={(event) =>
                                    router.visit(
                                        `/anak-saya/${event.target.value}`,
                                    )
                                }
                                className="h-9 max-w-[14rem] min-w-0 rounded-md border bg-background px-2 text-sm"
                            >
                                {siblings.map((sibling) => (
                                    <option key={sibling.id} value={sibling.id}>
                                        {sibling.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                    )}
                </div>

                <Heading
                    title={student.name}
                    description={`Anda tercatat sebagai: ${student.relation}`}
                />

                <div className="grid grid-cols-[repeat(auto-fit,minmax(10rem,1fr))] gap-2">
                    <Button asChild variant="outline">
                        <Link href={`/anak-saya/${student.id}/presensi`}>
                            <CalendarCheck />
                            Presensi
                        </Link>
                    </Button>
                    <Button asChild variant="outline">
                        <Link href={`/anak-saya/${student.id}/nilai`}>
                            <BookOpenCheck />
                            Nilai & rapor
                        </Link>
                    </Button>
                </div>

                <Section title="Profil">
                    <dl className="grid gap-3 sm:grid-cols-2">
                        <Item label="Jenis kelamin" value={student.gender} />
                        <Item label="NISN" value={student.nisn} mono />
                        <Item
                            label="Tempat, tanggal lahir"
                            value={
                                student.birth_place || student.birth_date
                                    ? [
                                          student.birth_place,
                                          student.birth_date
                                              ? formatDate(student.birth_date)
                                              : null,
                                      ]
                                          .filter(Boolean)
                                          .join(', ')
                                    : null
                            }
                        />
                    </dl>
                </Section>

                <Section title="Lembaga">
                    {enrollments.length === 0 ? (
                        <Empty>
                            Tidak terdaftar di lembaga mana pun saat ini.
                        </Empty>
                    ) : (
                        <ul className="divide-y">
                            {enrollments.map((enrollment) => (
                                <li
                                    key={enrollment.organization}
                                    className="flex flex-wrap justify-between gap-2 py-2 first:pt-0 last:pb-0"
                                >
                                    <span className="font-medium">
                                        {enrollment.organization}
                                    </span>
                                    <span className="text-sm text-muted-foreground">
                                        {enrollment.student_number
                                            ? `NIS ${enrollment.student_number} · `
                                            : ''}
                                        sejak{' '}
                                        {formatDate(enrollment.start_date)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Section>

                {classes.length === 0 ? (
                    <Section title="Kelas">
                        <Empty>
                            Belum masuk kelas pada tahun ajaran berjalan.
                        </Empty>
                    </Section>
                ) : (
                    classes.map((classInfo) => (
                        <Section
                            key={classInfo.id}
                            title={`Kelas ${classInfo.name}`}
                        >
                            <dl className="grid gap-3 sm:grid-cols-3">
                                <Item
                                    label="Lembaga"
                                    value={classInfo.organization}
                                />
                                <Item
                                    label="Tahun ajaran"
                                    value={classInfo.year}
                                />
                                <Item
                                    label="Wali kelas"
                                    value={classInfo.homeroom}
                                />
                            </dl>

                            <h3 className="pt-2 text-sm font-medium">
                                Jadwal pelajaran
                            </h3>
                            {classInfo.schedule.length === 0 ? (
                                <Empty>Jadwal belum diisi.</Empty>
                            ) : (
                                <ul className="divide-y rounded-lg border">
                                    {classInfo.schedule.map((lesson, index) => (
                                        <li
                                            key={`${lesson.day}-${lesson.start}-${index}`}
                                            className="grid grid-cols-[6.5rem_1fr] gap-x-3 px-3 py-2 text-sm"
                                        >
                                            <span className="text-muted-foreground tabular-nums">
                                                {dayLabel(lesson.day)}
                                                <br />
                                                {lesson.start}–{lesson.end}
                                            </span>
                                            <span className="min-w-0 break-words">
                                                <span className="font-medium">
                                                    {lesson.subject}
                                                </span>
                                                <br />
                                                <span className="text-muted-foreground">
                                                    {lesson.teacher ??
                                                        'Guru belum ditentukan'}
                                                </span>
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Section>
                    ))
                )}

                {calendars.map((calendar) => (
                    <Section
                        key={calendar.organization}
                        title={`Kalender akademik — ${calendar.organization}`}
                    >
                        {calendar.year === null ? (
                            <Empty>
                                Tahun ajaran berjalan belum ditetapkan.
                            </Empty>
                        ) : (
                            <div className="space-y-3">
                                <dl className="grid gap-3 sm:grid-cols-2">
                                    <Item
                                        label="Tahun ajaran"
                                        value={`${calendar.year.name} (${formatRange(calendar.year.start_date, calendar.year.end_date)})`}
                                    />
                                    <Item
                                        label="Libur mingguan"
                                        value={
                                            calendar.weekly_off_days.join(
                                                ', ',
                                            ) || null
                                        }
                                    />
                                </dl>

                                {calendar.terms.length > 0 && (
                                    <div className="space-y-1">
                                        <h3 className="text-sm font-medium">
                                            Semester
                                        </h3>
                                        <ul className="space-y-1 text-sm">
                                            {calendar.terms.map((term) => (
                                                <li key={term.name}>
                                                    {term.name}:{' '}
                                                    <span className="text-muted-foreground">
                                                        {formatRange(
                                                            term.start_date,
                                                            term.end_date,
                                                        )}
                                                    </span>
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                )}

                                <div className="space-y-1">
                                    <h3 className="text-sm font-medium">
                                        Hari libur
                                    </h3>
                                    {calendar.holidays.length === 0 ? (
                                        <Empty>Belum ada hari libur.</Empty>
                                    ) : (
                                        <ul className="divide-y rounded-lg border text-sm">
                                            {calendar.holidays.map(
                                                (holiday, index) => (
                                                    <li
                                                        key={`${holiday.start_date}-${index}`}
                                                        className={
                                                            'flex flex-wrap justify-between gap-x-3 px-3 py-2 ' +
                                                            (holiday.end_date <
                                                            today
                                                                ? 'text-muted-foreground'
                                                                : '')
                                                        }
                                                    >
                                                        <span className="break-words">
                                                            {holiday.name}
                                                        </span>
                                                        <span>
                                                            {formatRange(
                                                                holiday.start_date,
                                                                holiday.end_date,
                                                            )}
                                                        </span>
                                                    </li>
                                                ),
                                            )}
                                        </ul>
                                    )}
                                </div>
                            </div>
                        )}
                    </Section>
                ))}
            </div>
        </>
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
            <h2 className="text-base font-medium break-words">{title}</h2>
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

function Empty({ children }: { children: React.ReactNode }) {
    return <p className="text-sm text-muted-foreground">{children}</p>;
}
