import { Form, Head, Link, router } from '@inertiajs/react';
import { CalendarClock, Pencil, UserRoundSearch } from 'lucide-react';
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

type Props = {
    organizations: Option[];
    organization: Option;
    manages: boolean;
    date: string;
    today: string;
    holiday: string | null;
    lessons: TeachingLesson[];
    canRecord: boolean;
    /** Nama guru piket tanggal itu. */
    duty: string[];
    /** Calon pengganti per pertemuan (kunci = lesson.key). */
    candidates: Record<string, SubstituteCandidate[]>;
    reasons: Option[];
    noteMax: number;
};

const BASE_URL = '/akademik/presensi-mengajar';

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';

/**
 * Presensi mengajar per lembaga & tanggal (PRD-002 §20.2, AC-6a): guru piket hari ini, Operator
 * Akademik, dan Kepala Lembaga mencatat Mengajar / Digantikan / Kosong. Semua aturan dicek server.
 */
export default function TeachingIndex(props: Props) {
    const go = (query: { lembaga?: string; tanggal?: string }) =>
        router.get(
            BASE_URL,
            {
                lembaga: query.lembaga ?? props.organization.value,
                tanggal: query.tanggal ?? props.date,
            },
            { preserveState: false, replace: true },
        );

    const recorded = props.lessons.filter((lesson) => lesson.record).length;

    return (
        <>
            <Head title="Presensi mengajar" />

            <div className="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-4 p-4">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <Heading
                        title="Presensi mengajar"
                        description="Catat setiap pertemuan: guru mengajar, digantikan guru lain, atau kosong. Guru juga bisa menandai sendiri dari Kelas saya."
                    />
                    {/* Grid otomatis: tombol turun ke baris berikutnya di layar sempit. */}
                    <div className="grid grid-cols-[repeat(auto-fit,minmax(10rem,1fr))] gap-2 sm:flex">
                        <Button variant="outline" asChild>
                            <Link
                                href={`/akademik/perlu-pengganti?lembaga=${props.organization.value}`}
                            >
                                <UserRoundSearch />
                                Perlu pengganti
                            </Link>
                        </Button>
                        {props.manages && (
                            <Button variant="outline" asChild>
                                <Link
                                    href={`/akademik/jadwal-piket?lembaga=${props.organization.value}`}
                                >
                                    <CalendarClock />
                                    Jadwal piket
                                </Link>
                            </Button>
                        )}
                    </div>
                </div>

                <div className="grid grid-cols-[repeat(auto-fit,minmax(12rem,1fr))] gap-3 rounded-xl border p-4">
                    <div className="grid gap-1.5">
                        <Label htmlFor="lembaga">Lembaga</Label>
                        <select
                            id="lembaga"
                            className={selectClass}
                            value={props.organization.value}
                            onChange={(event) =>
                                go({ lembaga: event.target.value })
                            }
                        >
                            {props.organizations.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="tanggal">Tanggal</Label>
                        <Input
                            id="tanggal"
                            type="date"
                            value={props.date}
                            max={props.today}
                            disabled={!props.manages}
                            onChange={(event) =>
                                event.target.value &&
                                go({ tanggal: event.target.value })
                            }
                        />
                    </div>
                </div>

                <div className="space-y-1 text-sm">
                    <p className="font-medium">
                        {formatTeachingDate(props.date)}
                    </p>
                    <p className="text-muted-foreground">
                        Guru piket:{' '}
                        {props.duty.length === 0
                            ? 'belum diatur'
                            : props.duty.join(', ')}
                        {props.lessons.length > 0 &&
                            ` · ${recorded} dari ${props.lessons.length} pertemuan sudah dicatat`}
                    </p>
                    {!props.manages && (
                        <p className="text-muted-foreground">
                            Anda guru piket hari ini; hanya bisa mencatat
                            pertemuan hari ini.
                        </p>
                    )}
                </div>

                {props.holiday ? (
                    <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                        Libur: {props.holiday}. Tidak ada pertemuan.
                    </p>
                ) : props.lessons.length === 0 ? (
                    <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                        Tidak ada pertemuan terjadwal di lembaga ini pada
                        tanggal tersebut.
                    </p>
                ) : (
                    <ul className="space-y-3">
                        {props.lessons.map((lesson) => (
                            <LessonCard
                                key={lesson.key}
                                lesson={lesson}
                                {...props}
                            />
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}

function LessonCard({
    lesson,
    canRecord,
    candidates,
    reasons,
    noteMax,
    date,
}: Props & { lesson: TeachingLesson }) {
    const [editing, setEditing] = useState(false);
    const [status, setStatus] = useState<string>(
        lesson.record?.status ?? (lesson.leave ? 'DIGANTIKAN' : 'MENGAJAR'),
    );
    const editable = canRecord && lesson.entry_id !== null;

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
                    </p>
                    {lesson.leave && (
                        <p className="text-amber-700 dark:text-amber-300">
                            Pengampu cuti ({lesson.leave}) — perlu guru
                            pengganti
                        </p>
                    )}
                    {lesson.record?.note && (
                        <p className="break-words text-muted-foreground">
                            {lesson.record.note}
                        </p>
                    )}
                    {lesson.record && (
                        <p className="text-xs text-muted-foreground">
                            Dicatat{' '}
                            {lesson.record.source === 'GURU'
                                ? 'guru sendiri'
                                : (lesson.record.recorded_by ?? 'petugas')}
                        </p>
                    )}
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    {lesson.record ? (
                        <Badge
                            variant="outline"
                            className={
                                TEACHING_STATUS_CLASS[lesson.record.status]
                            }
                        >
                            {describeRecord(lesson.record)}
                        </Badge>
                    ) : (
                        <Badge
                            variant="outline"
                            className="border-dashed text-muted-foreground"
                        >
                            Belum dicatat
                        </Badge>
                    )}
                    {editable && !editing && (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => setEditing(true)}
                        >
                            <Pencil />
                            {lesson.record ? 'Ubah' : 'Catat'}
                        </Button>
                    )}
                </div>
            </div>

            {editable && editing && (
                <Form
                    action={`${BASE_URL}/${lesson.entry_id}`}
                    method="post"
                    errorBag={`lesson-${lesson.key}`}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setEditing(false)}
                    className="space-y-3 rounded-md border border-primary/30 bg-muted/20 p-3"
                >
                    {({ processing, errors }) => (
                        <>
                            <input type="hidden" name="date" value={date} />
                            <InputError message={errors.form ?? errors.date} />
                            <div
                                role="radiogroup"
                                aria-label="Status"
                                className="grid grid-cols-[repeat(auto-fit,minmax(8rem,1fr))] gap-2"
                            >
                                {(
                                    [
                                        ['MENGAJAR', 'Mengajar'],
                                        ['DIGANTIKAN', 'Digantikan'],
                                        ['KOSONG', 'Kosong'],
                                    ] as const
                                ).map(([value, label]) => (
                                    <label
                                        key={value}
                                        className={
                                            'flex min-h-10 cursor-pointer items-center gap-2 rounded-md border px-3 ' +
                                            (status === value
                                                ? 'border-primary bg-primary/5'
                                                : 'hover:bg-muted')
                                        }
                                    >
                                        <input
                                            type="radio"
                                            name="status"
                                            value={value}
                                            checked={status === value}
                                            onChange={() => setStatus(value)}
                                            className="size-4 accent-primary"
                                        />
                                        {label}
                                    </label>
                                ))}
                            </div>

                            {status !== 'MENGAJAR' && (
                                <div className="grid gap-3 sm:grid-cols-2">
                                    {status === 'DIGANTIKAN' && (
                                        <SubstituteSelect
                                            id={`sub-${lesson.key}`}
                                            candidates={
                                                candidates[lesson.key] ?? []
                                            }
                                            defaultValue={
                                                lesson.record?.substitute?.id ??
                                                ''
                                            }
                                            error={
                                                errors.substitute_employee_id
                                            }
                                        />
                                    )}
                                    <ReasonSelect
                                        id={`reason-${lesson.key}`}
                                        reasons={reasons}
                                        defaultValue={
                                            lesson.record?.reason ??
                                            (lesson.leave ? 'CUTI' : '')
                                        }
                                        error={errors.absence_reason}
                                    />
                                </div>
                            )}

                            <div className="grid gap-1.5">
                                <Label htmlFor={`note-${lesson.key}`}>
                                    Keterangan (opsional)
                                </Label>
                                <Input
                                    id={`note-${lesson.key}`}
                                    name="note"
                                    maxLength={noteMax}
                                    defaultValue={lesson.record?.note ?? ''}
                                />
                                <InputError message={errors.note} />
                            </div>

                            <div className="grid grid-cols-[repeat(auto-fit,minmax(8rem,1fr))] gap-2 sm:flex">
                                <Button type="submit" disabled={processing}>
                                    {processing ? 'Menyimpan…' : 'Simpan'}
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setEditing(false)}
                                >
                                    Batal
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            )}
        </li>
    );
}
