import { Form, Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, CircleAlert, RotateCcw } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Option = { value: string; label: string };

type Student = {
    enrollment_id: string;
    student_id: string;
    name: string;
    gender: string | null;
    nisn: string | null;
    student_number: string | null;
    status: string;
    note: string | null;
};

type Props = {
    class: { id: string; name: string; organization: string; year: string };
    /** Terisi = presensi per pertemuan (AC-4c). */
    lesson: {
        id: string;
        subject: string;
        day: number;
        day_label: string;
        start: string;
        end: string;
        teacher: string | null;
    } | null;
    /** Pesan bila jenis presensi ini tidak dipakai lembaga (mode berbeda). */
    blocked: string | null;
    window: { min: string; max: string } | null;
    date: string | null;
    sheet: {
        students: Student[];
        session: {
            recorded_by: string | null;
            updated_at: string | null;
        } | null;
    } | null;
    statuses: Option[];
    asHomeroom: boolean;
    homeroomDays: number;
    noteMax: number;
    baseUrl: string;
    backUrl: string;
    backLabel: string;
};

const PRESENT = 'H';

const dateFormat = new Intl.DateTimeFormat('id-ID', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
});
const stampFormat = new Intl.DateTimeFormat('id-ID', {
    dateStyle: 'medium',
    timeStyle: 'short',
});

/** "07:00" → "07.00" (penulisan jam di Indonesia). */
function displayTime(value: string): string {
    return value.replace(':', '.');
}

function formatDate(value: string): string {
    const [year, month, day] = value.split('-').map(Number);

    return dateFormat.format(new Date(year, month - 1, day));
}

/** Warna tombol status yang terpilih; kode tetap tertulis agar tidak bergantung pada warna. */
const activeClass: Record<string, string> = {
    H: 'border-emerald-600 bg-emerald-600 text-white',
    S: 'border-sky-600 bg-sky-600 text-white',
    I: 'border-amber-500 bg-amber-500 text-white',
    A: 'border-red-600 bg-red-600 text-white',
};

/**
 * Lembar presensi harian satu kelas (PRD-002 §17 AC-4a). Semua siswa awalnya Hadir; tandai yang
 * Sakit/Izin/Alpa lalu simpan semua sekaligus. Aturan tanggal & daftar siswa dicek server.
 */
export default function AttendanceSheet(props: Props) {
    const {
        class: item,
        lesson,
        window,
        date,
        asHomeroom,
        homeroomDays,
    } = props;
    const title = lesson
        ? `Presensi ${item.name} · ${lesson.subject}`
        : `Presensi ${item.name}`;

    return (
        <>
            <Head title={title} />

            <div className="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-4 p-4">
                <div>
                    <Link
                        href={props.backUrl}
                        className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                    >
                        <ArrowLeft className="size-4" />
                        {props.backLabel}
                    </Link>
                </div>

                <Heading
                    title={title}
                    description={
                        lesson
                            ? `${item.organization} · ${lesson.day_label} ${displayTime(lesson.start)}–${displayTime(lesson.end)}${lesson.teacher ? ` · ${lesson.teacher}` : ''}`
                            : `${item.organization} · Tahun ajaran ${item.year}`
                    }
                />

                {props.blocked ? (
                    <Notice>{props.blocked}</Notice>
                ) : window === null || date === null || props.sheet === null ? (
                    <Notice>
                        {lesson
                            ? `Tidak ada tanggal hari ${lesson.day_label} yang bisa diisi untuk jadwal ini (tahun ajaran sudah lewat, belum mulai, atau di luar batas hari).`
                            : 'Presensi kelas ini tidak bisa diisi: tahun ajarannya sudah lewat atau belum mulai.'}
                    </Notice>
                ) : (
                    <>
                        <div className="grid grid-cols-[repeat(auto-fit,minmax(14rem,1fr))] items-end gap-3 rounded-xl border p-4">
                            <div className="grid gap-1.5">
                                <Label htmlFor="att-date">Tanggal</Label>
                                <Input
                                    id="att-date"
                                    type="date"
                                    value={date}
                                    min={window.min}
                                    max={window.max}
                                    onChange={(event) => {
                                        if (event.target.value !== '') {
                                            router.get(
                                                props.baseUrl,
                                                { tanggal: event.target.value },
                                                { preserveScroll: true },
                                            );
                                        }
                                    }}
                                />
                            </div>
                            <p className="text-sm text-muted-foreground">
                                {lesson
                                    ? `Hanya tanggal hari ${lesson.day_label}. `
                                    : ''}
                                {asHomeroom
                                    ? `${lesson ? 'Guru pengampu' : 'Wali kelas'} bisa mengisi atau mengoreksi ${homeroomDays} hari terakhir.`
                                    : 'Operator bisa mengisi tanggal mana pun di tahun ajaran ini.'}
                            </p>
                        </div>

                        <SheetForm key={date} {...props} date={date} />
                    </>
                )}
            </div>
        </>
    );
}

function SheetForm({
    date,
    sheet,
    statuses,
    noteMax,
    baseUrl,
}: Props & { date: string }) {
    const students = sheet?.students ?? [];
    const session = sheet?.session ?? null;
    const [values, setValues] = useState<
        Record<string, { status: string; note: string }>
    >(() =>
        Object.fromEntries(
            students.map((student) => [
                student.enrollment_id,
                { status: student.status, note: student.note ?? '' },
            ]),
        ),
    );

    const set = (
        id: string,
        change: Partial<{ status: string; note: string }>,
    ) =>
        setValues((current) => ({
            ...current,
            [id]: { ...current[id], ...change },
        }));

    const counts = Object.fromEntries(
        statuses.map((status) => [
            status.value,
            Object.values(values).filter(
                (value) => value.status === status.value,
            ).length,
        ]),
    );

    if (students.length === 0) {
        return (
            <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                Belum ada siswa di kelas ini pada {formatDate(date)}.
            </p>
        );
    }

    return (
        <Form
            action={baseUrl}
            method="post"
            options={{ preserveScroll: true }}
            className="space-y-4"
        >
            {({ processing, errors }) => (
                <>
                    <input type="hidden" name="date" value={date} />

                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div className="space-y-0.5">
                            <h2 className="text-base font-medium">
                                {formatDate(date)}
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                {session
                                    ? `Sudah diisi${session.recorded_by ? ` oleh ${session.recorded_by}` : ''}${session.updated_at ? `, ${stampFormat.format(new Date(session.updated_at))}` : ''}. Simpan lagi untuk mengoreksi.`
                                    : 'Belum diisi. Semua siswa awalnya Hadir.'}
                            </p>
                        </div>
                        <div className="flex flex-wrap gap-1.5">
                            {statuses.map((status) => (
                                <Badge
                                    key={status.value}
                                    variant={
                                        status.value === PRESENT
                                            ? 'secondary'
                                            : 'outline'
                                    }
                                >
                                    {status.label} {counts[status.value] ?? 0}
                                </Badge>
                            ))}
                        </div>
                    </div>

                    <ul className="divide-y rounded-xl border">
                        {students.map((student, index) => {
                            const value = values[student.enrollment_id];

                            return (
                                <li
                                    key={student.enrollment_id}
                                    className="grid gap-2 px-4 py-3 sm:grid-cols-[1fr_auto] sm:items-center"
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
                                    <fieldset className="grid grid-cols-4 gap-1">
                                        <legend className="sr-only">
                                            Status kehadiran {student.name}
                                        </legend>
                                        {statuses.map((status) => {
                                            const checked =
                                                value.status === status.value;

                                            return (
                                                <label
                                                    key={status.value}
                                                    title={status.label}
                                                    className={
                                                        'flex h-9 min-w-11 cursor-pointer items-center justify-center rounded-md border text-sm font-medium focus-within:ring-[3px] focus-within:ring-ring/50 ' +
                                                        (checked
                                                            ? activeClass[
                                                                  status.value
                                                              ]
                                                            : 'bg-background hover:bg-muted')
                                                    }
                                                >
                                                    <input
                                                        type="radio"
                                                        className="sr-only"
                                                        name={`attendance[${student.enrollment_id}][status]`}
                                                        value={status.value}
                                                        checked={checked}
                                                        onChange={() =>
                                                            set(
                                                                student.enrollment_id,
                                                                {
                                                                    status: status.value,
                                                                },
                                                            )
                                                        }
                                                    />
                                                    {status.value}
                                                    <span className="sr-only">
                                                        {' '}
                                                        {status.label}
                                                    </span>
                                                </label>
                                            );
                                        })}
                                    </fieldset>
                                    {(value.status !== PRESENT ||
                                        value.note !== '') && (
                                        <div className="sm:col-span-2">
                                            <Label
                                                htmlFor={`att-note-${student.enrollment_id}`}
                                                className="sr-only"
                                            >
                                                Keterangan {student.name}
                                            </Label>
                                            <Input
                                                id={`att-note-${student.enrollment_id}`}
                                                name={`attendance[${student.enrollment_id}][note]`}
                                                value={value.note}
                                                maxLength={noteMax}
                                                placeholder="Keterangan (opsional)"
                                                onChange={(event) =>
                                                    set(student.enrollment_id, {
                                                        note: event.target
                                                            .value,
                                                    })
                                                }
                                            />
                                        </div>
                                    )}
                                </li>
                            );
                        })}
                    </ul>

                    <p className="text-xs text-muted-foreground">
                        H = Hadir · S = Sakit · I = Izin · A = Alpa
                    </p>

                    <InputError
                        message={
                            errors.form ?? errors.date ?? errors.attendance
                        }
                    />

                    <div className="grid grid-cols-[repeat(auto-fit,minmax(10rem,1fr))] gap-2 sm:flex">
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Menyimpan…' : 'Simpan presensi'}
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() =>
                                setValues((current) =>
                                    Object.fromEntries(
                                        Object.keys(current).map((id) => [
                                            id,
                                            { status: PRESENT, note: '' },
                                        ]),
                                    ),
                                )
                            }
                        >
                            <RotateCcw />
                            Semua hadir
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

function Notice({ children }: { children: React.ReactNode }) {
    return (
        <p className="flex gap-2 rounded-md border border-amber-500/50 bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
            <CircleAlert className="mt-0.5 size-4 shrink-0" />
            <span>{children}</span>
        </p>
    );
}
