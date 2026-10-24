import { Form, Head, Link } from '@inertiajs/react';
import { Check, ChevronRight, ClipboardCheck } from 'lucide-react';
import type {
    SubstituteTask,
    TeachingLesson,
} from '@/components/academic/teaching';
import {
    describeRecord,
    formatTeachingDate,
    TEACHING_STATUS_CLASS,
} from '@/components/academic/teaching';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

type MyClass = {
    id: string;
    name: string;
    grade: number;
    organization: string;
    year: string;
    homeroom: boolean;
    subjects: string[];
    lesson_mode: boolean;
};

type Lesson = {
    entry_id: string;
    day: number;
    start: string;
    end: string;
    subject: string;
    class_id: string;
    class_name: string;
    organization: string;
};

type Props = {
    linked: boolean;
    classes: MyClass[];
    schedule: Lesson[];
    days: { value: number; label: string }[];
    /** Pertemuan 7 hari terakhir beserta presensi mengajarnya (AC-6a). */
    teaching: TeachingLesson[];
    /** Pertemuan yang Anda gantikan, 6 hari terakhir s.d. 7 hari ke depan (AC-6b-2). */
    substitutions: SubstituteTask[];
    today: string;
    now: string;
};

/** "07:00" → "07.00" (penulisan jam di Indonesia). */
function displayTime(value: string): string {
    return value.replace(':', '.');
}

/**
 * Kelas saya (PRD-002 §16 AC-3d): kelas yang dipegang wali kelas/guru pengampu yang sedang login
 * dan jadwal mengajarnya. Hanya bisa dilihat.
 */
export default function MyClasses({
    linked,
    classes,
    schedule,
    days,
    teaching,
    substitutions,
    today,
    now,
}: Props) {
    // Kelas di lembaga yang memakai presensi per pertemuan (AC-4c).
    const lessonMode = new Set(
        classes.filter((item) => item.lesson_mode).map((item) => item.id),
    );
    const usedDays = days.filter((day) =>
        schedule.some((lesson) => lesson.day === day.value),
    );

    return (
        <>
            <Head title="Kelas saya" />

            <div className="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-4 p-4">
                <Heading
                    title="Kelas saya"
                    description="Kelas yang Anda pegang sebagai wali kelas atau guru pengampu di tahun ajaran berjalan."
                />

                {!linked ? (
                    <EmptyState text="Akun Anda belum tertaut ke data pegawai. Hubungi Admin SDM bila Anda wali kelas atau guru pengampu." />
                ) : classes.length === 0 ? (
                    substitutions.length === 0 ? (
                        <EmptyState text="Anda belum memegang kelas di tahun ajaran berjalan." />
                    ) : (
                        <SubstitutionsSection
                            tasks={substitutions}
                            today={today}
                        />
                    )
                ) : (
                    <>
                        <section className="space-y-3">
                            <h2 className="text-base font-medium">
                                Kelas ({classes.length})
                            </h2>
                            <ul className="grid gap-3 sm:grid-cols-[repeat(auto-fit,minmax(16rem,1fr))]">
                                {classes.map((item) => (
                                    <li key={item.id}>
                                        <Link
                                            href={`/kelas-saya/${item.id}`}
                                            className="flex h-full items-start justify-between gap-2 rounded-xl border p-4 hover:bg-muted/40"
                                        >
                                            <div className="min-w-0 space-y-1">
                                                <p className="font-medium break-words">
                                                    {item.name}
                                                </p>
                                                <p className="text-sm text-muted-foreground">
                                                    {item.organization} ·
                                                    Tingkat {item.grade} ·{' '}
                                                    {item.year}
                                                </p>
                                                <div className="flex flex-wrap gap-1.5 pt-1">
                                                    {item.homeroom && (
                                                        <Badge>
                                                            Wali kelas
                                                        </Badge>
                                                    )}
                                                    {item.subjects.map(
                                                        (subject) => (
                                                            <Badge
                                                                key={subject}
                                                                variant="secondary"
                                                            >
                                                                {subject}
                                                            </Badge>
                                                        ),
                                                    )}
                                                </div>
                                            </div>
                                            <ChevronRight className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </section>

                        <section className="space-y-3 rounded-xl border p-4">
                            <h2 className="text-base font-medium">
                                Jadwal mengajar
                            </h2>
                            {schedule.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    Belum ada jadwal untuk mapel yang Anda ampu.
                                </p>
                            ) : (
                                <div className="grid gap-3 sm:grid-cols-[repeat(auto-fit,minmax(16rem,1fr))]">
                                    {usedDays.map((day) => (
                                        <div
                                            key={day.value}
                                            className="rounded-lg border"
                                        >
                                            <h3 className="border-b bg-muted/40 px-3 py-1.5 text-sm font-medium">
                                                {day.label}
                                            </h3>
                                            <ul className="divide-y">
                                                {schedule
                                                    .filter(
                                                        (lesson) =>
                                                            lesson.day ===
                                                            day.value,
                                                    )
                                                    .map((lesson) => (
                                                        <li
                                                            key={
                                                                lesson.entry_id
                                                            }
                                                            className="px-3 py-2 text-sm"
                                                        >
                                                            <p>
                                                                <span className="font-medium tabular-nums">
                                                                    {displayTime(
                                                                        lesson.start,
                                                                    )}
                                                                    –
                                                                    {displayTime(
                                                                        lesson.end,
                                                                    )}
                                                                </span>{' '}
                                                                {lesson.subject}
                                                            </p>
                                                            <p className="text-muted-foreground">
                                                                {
                                                                    lesson.class_name
                                                                }{' '}
                                                                ·{' '}
                                                                {
                                                                    lesson.organization
                                                                }
                                                            </p>
                                                            {lessonMode.has(
                                                                lesson.class_id,
                                                            ) && (
                                                                <Link
                                                                    href={`/kelas-saya/jadwal/${lesson.entry_id}/presensi`}
                                                                    className="mt-1 inline-flex items-center gap-1 text-primary hover:underline"
                                                                >
                                                                    <ClipboardCheck className="size-3.5" />
                                                                    Isi presensi
                                                                </Link>
                                                            )}
                                                        </li>
                                                    ))}
                                            </ul>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </section>

                        {substitutions.length > 0 && (
                            <SubstitutionsSection
                                tasks={substitutions}
                                today={today}
                            />
                        )}

                        <TeachingSection
                            lessons={teaching}
                            today={today}
                            now={now}
                        />
                    </>
                )}
            </div>
        </>
    );
}

/**
 * Presensi mengajar 7 hari terakhir (PRD-002 §20.2): tandai "Sudah mengajar"; catatan petugas
 * hanya ditampilkan.
 */
function TeachingSection({
    lessons,
    today,
    now,
}: {
    lessons: TeachingLesson[];
    today: string;
    now: string;
}) {
    const dates = [...new Set(lessons.map((lesson) => lesson.date))];

    return (
        <section
            aria-label="Presensi mengajar"
            className="space-y-3 rounded-xl border p-4"
        >
            <div>
                <h2 className="text-base font-medium">
                    Presensi mengajar 7 hari terakhir
                </h2>
                <p className="text-sm text-muted-foreground">
                    Tandai setiap pertemuan yang sudah Anda ajar. Bila
                    berhalangan, guru piket atau operator lembaga yang mencatat
                    penggantinya.
                </p>
            </div>
            {lessons.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    Tidak ada pertemuan terjadwal dalam 7 hari terakhir.
                </p>
            ) : (
                <div className="space-y-3">
                    {dates.map((date) => (
                        <div key={date} className="rounded-lg border">
                            <h3 className="border-b bg-muted/40 px-3 py-1.5 text-sm font-medium">
                                {formatTeachingDate(date)}
                                {date === today && ' (hari ini)'}
                            </h3>
                            <ul className="divide-y">
                                {lessons
                                    .filter((lesson) => lesson.date === date)
                                    .map((lesson) => (
                                        <TeachingRow
                                            key={lesson.key}
                                            lesson={lesson}
                                            started={
                                                date < today ||
                                                now >= lesson.start
                                            }
                                        />
                                    ))}
                            </ul>
                        </div>
                    ))}
                </div>
            )}
        </section>
    );
}

function TeachingRow({
    lesson,
    started,
}: {
    lesson: TeachingLesson;
    started: boolean;
}) {
    return (
        <li className="flex flex-wrap items-start justify-between gap-2 px-3 py-2 text-sm">
            <div className="min-w-0">
                <p>
                    <span className="font-medium tabular-nums">
                        {displayTime(lesson.start)}–{displayTime(lesson.end)}
                    </span>{' '}
                    {lesson.subject}
                </p>
                <p className="text-muted-foreground">
                    {lesson.class_name} · {lesson.organization}
                </p>
            </div>
            {lesson.record ? (
                <Badge
                    variant="outline"
                    className={TEACHING_STATUS_CLASS[lesson.record.status]}
                >
                    {describeRecord(lesson.record)}
                </Badge>
            ) : lesson.leave ? (
                <Badge variant="outline" className="text-muted-foreground">
                    Cuti ({lesson.leave})
                </Badge>
            ) : lesson.entry_id && started ? (
                <Form
                    action={`/kelas-saya/jadwal/${lesson.entry_id}/mengajar`}
                    method="post"
                    errorBag={`teach-${lesson.key}`}
                    options={{ preserveScroll: true }}
                    className="grid justify-items-end gap-1"
                >
                    {({ processing, errors }) => (
                        <>
                            <input
                                type="hidden"
                                name="date"
                                value={lesson.date}
                            />
                            <Button
                                type="submit"
                                size="sm"
                                disabled={processing}
                            >
                                <Check />
                                Sudah mengajar
                            </Button>
                            <InputError message={errors.form} />
                        </>
                    )}
                </Form>
            ) : (
                <span className="text-muted-foreground">Belum dimulai</span>
            )}
        </li>
    );
}

/**
 * Tugas menggantikan (PRD-002 §20.3, AC-6b-2): pertemuan guru lain yang ditunjuk untuk Anda.
 * Di lembaga yang memakai presensi per pertemuan, Anda mengisi presensi siswanya.
 */
function SubstitutionsSection({
    tasks,
    today,
}: {
    tasks: SubstituteTask[];
    today: string;
}) {
    const dates = [...new Set(tasks.map((task) => task.date))];

    return (
        <section
            aria-label="Tugas menggantikan"
            className="space-y-3 rounded-xl border border-sky-500/40 p-4"
        >
            <div>
                <h2 className="text-base font-medium">Tugas menggantikan</h2>
                <p className="text-sm text-muted-foreground">
                    Pertemuan guru lain yang ditunjuk untuk Anda gantikan. Bila
                    lembaganya memakai presensi per pertemuan, isi presensi
                    siswanya pada hari-H (paling lambat 6 hari sesudahnya).
                </p>
            </div>
            <div className="space-y-3">
                {dates.map((date) => (
                    <div key={date} className="rounded-lg border">
                        <h3 className="border-b bg-muted/40 px-3 py-1.5 text-sm font-medium">
                            {formatTeachingDate(date)}
                            {date === today && ' (hari ini)'}
                        </h3>
                        <ul className="divide-y">
                            {tasks
                                .filter((task) => task.date === date)
                                .map((task) => (
                                    <li
                                        key={task.key}
                                        className="flex flex-wrap items-start justify-between gap-2 px-3 py-2 text-sm"
                                    >
                                        <div className="min-w-0">
                                            <p>
                                                <span className="font-medium tabular-nums">
                                                    {displayTime(task.start)}–
                                                    {displayTime(task.end)}
                                                </span>{' '}
                                                {task.subject} ·{' '}
                                                {task.class_name}
                                            </p>
                                            <p className="text-muted-foreground">
                                                {task.organization} ·
                                                menggantikan{' '}
                                                {task.teacher ?? 'pengampu'}
                                                {task.reason &&
                                                    ` (${task.reason})`}
                                            </p>
                                        </div>
                                        {task.can_fill && task.entry_id ? (
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                asChild
                                            >
                                                <Link
                                                    href={`/kelas-saya/menggantikan/${task.entry_id}/${task.date}/presensi`}
                                                >
                                                    <ClipboardCheck />
                                                    Isi presensi siswa
                                                </Link>
                                            </Button>
                                        ) : (
                                            <span className="text-muted-foreground">
                                                {!task.lesson_mode
                                                    ? 'Presensi harian oleh wali kelas'
                                                    : task.date > today
                                                      ? 'Akan datang'
                                                      : 'Lewat batas isi'}
                                            </span>
                                        )}
                                    </li>
                                ))}
                        </ul>
                    </div>
                ))}
            </div>
        </section>
    );
}

function EmptyState({ text }: { text: string }) {
    return (
        <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
            {text}
        </p>
    );
}
