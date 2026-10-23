import { Form, Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, UserRoundCheck, X } from 'lucide-react';
import { useState } from 'react';
import {
    ReasonSelect,
    SubstituteSelect,
} from '@/components/academic/substitute-fields';
import type {
    SubstituteCandidate,
    TeachingLesson,
} from '@/components/academic/teaching';
import {
    describeRecord,
    displayTime,
    formatTeachingDate,
    TEACHING_STATUS_CLASS,
} from '@/components/academic/teaching';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Option = { value: string; label: string };

type Day = {
    date: string;
    duty: string[];
    lessons: TeachingLesson[];
    candidates: Record<string, SubstituteCandidate[]>;
};

type Props = {
    organizations: Option[];
    organization: Option;
    manages: boolean;
    today: string;
    aheadDays: number;
    days: Day[];
    reasons: Option[];
    noteMax: number;
};

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';

/**
 * Perlu pengganti (PRD-002 §20.3, AC-6b): pertemuan hari ini s.d. 7 hari ke depan yang pengampunya
 * cuti disetujui, atau yang tercatat Kosong. Tunjuk guru pengganti dari saran (piket & yang tidak
 * bentrok). Semua aturan dicek server.
 */
export default function Substitutes(props: Props) {
    const waiting = props.days.reduce(
        (total, day) =>
            total +
            day.lessons.filter(
                (lesson) => lesson.record?.status !== 'DIGANTIKAN',
            ).length,
        0,
    );

    return (
        <>
            <Head title="Perlu pengganti" />

            <div className="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-4 p-4">
                <Button
                    variant="ghost"
                    size="sm"
                    asChild
                    className="self-start"
                >
                    <Link
                        href={`/akademik/presensi-mengajar?lembaga=${props.organization.value}`}
                    >
                        <ArrowLeft />
                        Presensi mengajar
                    </Link>
                </Button>

                <Heading
                    title="Perlu pengganti"
                    description={
                        props.aheadDays > 0
                            ? `Pertemuan hari ini sampai ${props.aheadDays} hari ke depan yang pengampunya cuti, atau yang tercatat kosong. Guru piket dan guru yang jamnya kosong disarankan lebih dulu.`
                            : 'Pertemuan hari ini yang pengampunya cuti atau tercatat kosong. Guru piket dan guru yang jamnya kosong disarankan lebih dulu.'
                    }
                />

                <div className="grid grid-cols-[repeat(auto-fit,minmax(12rem,1fr))] gap-3 rounded-xl border p-4">
                    <div className="grid gap-1.5">
                        <Label htmlFor="lembaga">Lembaga</Label>
                        <select
                            id="lembaga"
                            className={selectClass}
                            value={props.organization.value}
                            onChange={(event) =>
                                router.get(
                                    '/akademik/perlu-pengganti',
                                    { lembaga: event.target.value },
                                    { preserveState: false, replace: true },
                                )
                            }
                        >
                            {props.organizations.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <p className="self-end text-sm text-muted-foreground">
                        {waiting === 0
                            ? 'Semua sudah punya pengganti.'
                            : `${waiting} pertemuan belum punya pengganti.`}
                    </p>
                </div>

                {props.days.length === 0 ? (
                    <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                        Tidak ada pertemuan yang perlu guru pengganti.
                    </p>
                ) : (
                    props.days.map((day) => (
                        <section
                            key={day.date}
                            aria-label={formatTeachingDate(day.date)}
                            className="space-y-2"
                        >
                            <div>
                                <h2 className="text-base font-medium">
                                    {formatTeachingDate(day.date)}
                                    {day.date === props.today && ' (hari ini)'}
                                </h2>
                                <p className="text-sm text-muted-foreground">
                                    Guru piket:{' '}
                                    {day.duty.length === 0
                                        ? 'belum diatur'
                                        : day.duty.join(', ')}
                                </p>
                            </div>
                            <ul className="space-y-3">
                                {day.lessons.map((lesson) => (
                                    <NeedCard
                                        key={lesson.key}
                                        lesson={lesson}
                                        candidates={
                                            day.candidates[lesson.key] ?? []
                                        }
                                        reasons={props.reasons}
                                        noteMax={props.noteMax}
                                    />
                                ))}
                            </ul>
                        </section>
                    ))
                )}
            </div>
        </>
    );
}

function NeedCard({
    lesson,
    candidates,
    reasons,
    noteMax,
}: {
    lesson: TeachingLesson;
    candidates: SubstituteCandidate[];
    reasons: Option[];
    noteMax: number;
}) {
    const assigned = lesson.record?.status === 'DIGANTIKAN';
    const [editing, setEditing] = useState(!assigned);

    return (
        <li className="space-y-3 rounded-xl border p-4 text-sm">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0 space-y-0.5">
                    <p>
                        <span className="font-medium tabular-nums">
                            {displayTime(lesson.start)}–
                            {displayTime(lesson.end)}
                        </span>{' '}
                        {lesson.subject} · {lesson.class_name}
                    </p>
                    <p className="text-muted-foreground">
                        Pengampu: {lesson.teacher?.name ?? 'belum ada'}
                        {lesson.leave && ` · cuti (${lesson.leave})`}
                    </p>
                </div>
                {lesson.record ? (
                    <Badge
                        variant="outline"
                        className={TEACHING_STATUS_CLASS[lesson.record.status]}
                    >
                        {describeRecord(lesson.record)}
                    </Badge>
                ) : (
                    <Badge
                        variant="outline"
                        className="border-amber-500/50 text-amber-800 dark:text-amber-200"
                    >
                        Belum ada pengganti
                    </Badge>
                )}
            </div>

            {assigned && !editing && (
                <div className="grid grid-cols-[repeat(auto-fit,minmax(10rem,1fr))] gap-2 sm:flex">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => setEditing(true)}
                    >
                        <UserRoundCheck />
                        Ganti pengganti
                    </Button>
                    <Form
                        action={`/akademik/presensi-mengajar/${lesson.entry_id}/batalkan-pengganti`}
                        method="post"
                        errorBag={`clear-${lesson.key}`}
                        options={{ preserveScroll: true }}
                        className="grid gap-1"
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
                                    variant="outline"
                                    size="sm"
                                    disabled={processing}
                                    className="w-full"
                                >
                                    <X />
                                    Batalkan
                                </Button>
                                <InputError message={errors.form} />
                            </>
                        )}
                    </Form>
                </div>
            )}

            {editing && (
                <Form
                    action={`/akademik/presensi-mengajar/${lesson.entry_id}`}
                    method="post"
                    errorBag={`assign-${lesson.key}`}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setEditing(false)}
                    className="space-y-3 rounded-md border border-primary/30 bg-muted/20 p-3"
                >
                    {({ processing, errors }) => (
                        <>
                            <input
                                type="hidden"
                                name="date"
                                value={lesson.date}
                            />
                            <input
                                type="hidden"
                                name="status"
                                value="DIGANTIKAN"
                            />
                            <InputError message={errors.form ?? errors.date} />
                            <div className="grid gap-3 sm:grid-cols-2">
                                <SubstituteSelect
                                    id={`need-sub-${lesson.key}`}
                                    candidates={candidates}
                                    defaultValue={
                                        lesson.record?.substitute?.id ?? ''
                                    }
                                    error={errors.substitute_employee_id}
                                />
                                <ReasonSelect
                                    id={`need-reason-${lesson.key}`}
                                    reasons={reasons}
                                    defaultValue={
                                        lesson.record?.reason ??
                                        (lesson.leave ? 'CUTI' : '')
                                    }
                                    error={errors.absence_reason}
                                />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor={`need-note-${lesson.key}`}>
                                    Keterangan (opsional)
                                </Label>
                                <Input
                                    id={`need-note-${lesson.key}`}
                                    name="note"
                                    maxLength={noteMax}
                                    defaultValue={lesson.record?.note ?? ''}
                                    placeholder="Mis. materi: latihan soal bab 3"
                                />
                                <InputError message={errors.note} />
                            </div>
                            <div className="grid grid-cols-[repeat(auto-fit,minmax(8rem,1fr))] gap-2 sm:flex">
                                <Button type="submit" disabled={processing}>
                                    {processing
                                        ? 'Menyimpan…'
                                        : 'Tunjuk pengganti'}
                                </Button>
                                {assigned && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => setEditing(false)}
                                    >
                                        Batal
                                    </Button>
                                )}
                            </div>
                        </>
                    )}
                </Form>
            )}
        </li>
    );
}
