import { CalendarClock, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { ClassSubjectRow } from '@/components/academic/class-subject-actions';
import type { Option } from '@/components/academic/student-actions';
import {
    ActionForm,
    Field,
    SelectOptions,
} from '@/components/academic/student-actions';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

/*
 * Jadwal pelajaran mingguan di detail kelas (PRD-002 §16 AC-3c). Bentrok kelas & bentrok guru
 * diperiksa server; form di sini hanya membantu.
 */

export type ScheduleRow = {
    id: string;
    class_subject_id: string;
    day: number;
    start: string;
    end: string;
    subject: string;
    teacher: string | null;
};

const DAYS: Option[] = [
    { value: '1', label: 'Senin' },
    { value: '2', label: 'Selasa' },
    { value: '3', label: 'Rabu' },
    { value: '4', label: 'Kamis' },
    { value: '5', label: 'Jumat' },
    { value: '6', label: 'Sabtu' },
    { value: '7', label: 'Ahad' },
];

/** "07:00" → "07.00" (penulisan jam di Indonesia). */
function displayTime(value: string): string {
    return value.replace(':', '.');
}

type OpenAction =
    | { kind: 'add' }
    | { kind: 'edit'; id: string }
    | { kind: 'remove'; id: string };

export function ScheduleSection({
    classId,
    schedule,
    subjects,
    canManage,
}: {
    classId: string;
    schedule: ScheduleRow[];
    subjects: ClassSubjectRow[];
    canManage: boolean;
}) {
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
    const isOpen = (kind: OpenAction['kind'], id: string) =>
        action !== null &&
        action.kind === kind &&
        'id' in action &&
        action.id === id;

    const subjectOptions: Option[] = subjects.map((subject) => ({
        value: subject.id,
        label: subject.teacher
            ? `${subject.name} — ${subject.teacher.name}`
            : subject.name,
    }));
    const days = DAYS.filter((day) =>
        schedule.some((entry) => String(entry.day) === day.value),
    );

    return (
        <section className="space-y-3 rounded-xl border p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="flex items-center gap-2 text-base font-medium">
                    <CalendarClock className="size-4" />
                    Jadwal pelajaran
                </h2>
                {canManage && subjects.length > 0 && (
                    <Button
                        type="button"
                        size="sm"
                        variant={action?.kind === 'add' ? 'default' : 'outline'}
                        onClick={() => toggle({ kind: 'add' })}
                        className="w-full sm:w-auto"
                    >
                        <Plus />
                        Tambah jadwal
                    </Button>
                )}
            </div>

            {canManage && subjects.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    Tambahkan mata pelajaran ke kelas ini dulu, baru jadwalnya
                    bisa diisi.
                </p>
            )}

            {canManage && action?.kind === 'add' && (
                <EntryForm
                    action={`/kelas/${classId}/jadwal`}
                    method="post"
                    subjectOptions={subjectOptions}
                    onDone={close}
                />
            )}

            {schedule.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    Belum ada jadwal untuk kelas ini.
                </p>
            ) : (
                <div className="grid gap-3 sm:grid-cols-[repeat(auto-fit,minmax(16rem,1fr))]">
                    {days.map((day) => (
                        <div key={day.value} className="rounded-lg border">
                            <h3 className="border-b bg-muted/40 px-3 py-1.5 text-sm font-medium">
                                {day.label}
                            </h3>
                            <ul className="divide-y">
                                {schedule
                                    .filter(
                                        (entry) =>
                                            String(entry.day) === day.value,
                                    )
                                    .map((entry) => (
                                        <li
                                            key={entry.id}
                                            className="space-y-2 px-3 py-2"
                                        >
                                            <div className="flex flex-wrap items-start justify-between gap-2 text-sm">
                                                <div className="min-w-0">
                                                    <p>
                                                        <span className="font-medium tabular-nums">
                                                            {displayTime(
                                                                entry.start,
                                                            )}
                                                            –
                                                            {displayTime(
                                                                entry.end,
                                                            )}
                                                        </span>{' '}
                                                        {entry.subject}
                                                    </p>
                                                    <p className="text-muted-foreground">
                                                        {entry.teacher ??
                                                            'Pengampu belum ada'}
                                                    </p>
                                                </div>
                                                {canManage && (
                                                    <div className="flex gap-1">
                                                        <Button
                                                            type="button"
                                                            size="icon"
                                                            variant={
                                                                isOpen(
                                                                    'edit',
                                                                    entry.id,
                                                                )
                                                                    ? 'default'
                                                                    : 'ghost'
                                                            }
                                                            aria-label={`Ubah jadwal ${entry.subject}`}
                                                            onClick={() =>
                                                                toggle({
                                                                    kind: 'edit',
                                                                    id: entry.id,
                                                                })
                                                            }
                                                        >
                                                            <Pencil />
                                                        </Button>
                                                        <Button
                                                            type="button"
                                                            size="icon"
                                                            variant={
                                                                isOpen(
                                                                    'remove',
                                                                    entry.id,
                                                                )
                                                                    ? 'destructive'
                                                                    : 'ghost'
                                                            }
                                                            aria-label={`Hapus jadwal ${entry.subject}`}
                                                            onClick={() =>
                                                                toggle({
                                                                    kind: 'remove',
                                                                    id: entry.id,
                                                                })
                                                            }
                                                        >
                                                            <Trash2 />
                                                        </Button>
                                                    </div>
                                                )}
                                            </div>
                                            {canManage &&
                                                isOpen('edit', entry.id) && (
                                                    <EntryForm
                                                        entry={entry}
                                                        action={`/kelas/jadwal/${entry.id}`}
                                                        method="patch"
                                                        subjectOptions={
                                                            subjectOptions
                                                        }
                                                        onDone={close}
                                                    />
                                                )}
                                            {canManage &&
                                                isOpen('remove', entry.id) && (
                                                    <ActionForm
                                                        title="Hapus jadwal"
                                                        action={`/kelas/jadwal/${entry.id}`}
                                                        method="delete"
                                                        errorBag="removeSchedule"
                                                        submitLabel="Hapus jadwal"
                                                        destructive
                                                        onDone={close}
                                                        note={`${day.label} ${displayTime(entry.start)}–${displayTime(entry.end)} ${entry.subject}.`}
                                                    >
                                                        {() => null}
                                                    </ActionForm>
                                                )}
                                        </li>
                                    ))}
                            </ul>
                        </div>
                    ))}
                </div>
            )}
        </section>
    );
}

/** Tambah (tanpa `entry`) atau ubah satu baris jadwal. */
function EntryForm({
    entry,
    action,
    method,
    subjectOptions,
    onDone,
}: {
    entry?: ScheduleRow;
    action: string;
    method: 'post' | 'patch';
    subjectOptions: Option[];
    onDone: () => void;
}) {
    const key = entry?.id ?? 'new';

    return (
        <ActionForm
            title={entry ? 'Ubah jadwal' : 'Tambah jadwal'}
            action={action}
            method={method}
            errorBag={entry ? 'editSchedule' : 'addSchedule'}
            submitLabel={entry ? 'Simpan jadwal' : 'Tambah jadwal'}
            onDone={onDone}
            note="Jadwal yang jamnya beririsan dengan jadwal kelas ini, atau dengan jadwal guru pengampunya di kelas lain, ditolak."
        >
            {(errors) => (
                <>
                    <Field
                        id={`sch-day-${key}`}
                        label="Hari"
                        required
                        error={errors.day_of_week}
                    >
                        <SelectOptions
                            id={`sch-day-${key}`}
                            name="day_of_week"
                            options={DAYS}
                            placeholder="Pilih hari"
                            defaultValue={entry ? String(entry.day) : ''}
                            required
                        />
                    </Field>
                    <Field
                        id={`sch-subject-${key}`}
                        label="Mata pelajaran"
                        required
                        error={errors.class_subject_id}
                    >
                        <SelectOptions
                            id={`sch-subject-${key}`}
                            name="class_subject_id"
                            options={subjectOptions}
                            placeholder="Pilih mata pelajaran"
                            defaultValue={entry?.class_subject_id ?? ''}
                            required
                        />
                    </Field>
                    <Field
                        id={`sch-start-${key}`}
                        label="Jam mulai"
                        required
                        error={errors.start_time}
                    >
                        <Input
                            id={`sch-start-${key}`}
                            name="start_time"
                            type="time"
                            defaultValue={entry?.start ?? ''}
                            required
                        />
                    </Field>
                    <Field
                        id={`sch-end-${key}`}
                        label="Jam selesai"
                        required
                        error={errors.end_time}
                    >
                        <Input
                            id={`sch-end-${key}`}
                            name="end_time"
                            type="time"
                            defaultValue={entry?.end ?? ''}
                            required
                        />
                    </Field>
                </>
            )}
        </ActionForm>
    );
}
