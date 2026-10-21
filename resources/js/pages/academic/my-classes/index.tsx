import { Head, Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';

type MyClass = {
    id: string;
    name: string;
    grade: number;
    organization: string;
    year: string;
    homeroom: boolean;
    subjects: string[];
};

type Lesson = {
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
};

/** "07:00" → "07.00" (penulisan jam di Indonesia). */
function displayTime(value: string): string {
    return value.replace(':', '.');
}

/**
 * Kelas saya (PRD-002 §16 AC-3d): kelas yang dipegang wali kelas/guru pengampu yang sedang login
 * dan jadwal mengajarnya. Hanya bisa dilihat.
 */
export default function MyClasses({ linked, classes, schedule, days }: Props) {
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
                    <EmptyState text="Anda belum memegang kelas di tahun ajaran berjalan." />
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
                                                            key={`${lesson.class_id}-${lesson.start}-${lesson.subject}`}
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
                                                        </li>
                                                    ))}
                                            </ul>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </section>
                    </>
                )}
            </div>
        </>
    );
}

function EmptyState({ text }: { text: string }) {
    return (
        <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
            {text}
        </p>
    );
}
