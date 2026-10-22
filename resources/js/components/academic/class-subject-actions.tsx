import { Form } from '@inertiajs/react';
import {
    BookOpen,
    CircleAlert,
    Copy,
    Plus,
    Trash2,
    UserCog,
} from 'lucide-react';
import { useState } from 'react';
import type { Option } from '@/components/academic/student-actions';
import {
    ActionForm,
    Field,
    SelectOptions,
} from '@/components/academic/student-actions';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';

/*
 * Mata pelajaran kelas & guru pengampu di detail kelas (PRD-002 §16 AC-3b). Semua aturan & hak
 * akses tetap dicek server; form di sini hanya membantu (pilihan yang tersedia).
 */

export type ClassSubjectRow = {
    id: string;
    subject_id: string;
    name: string;
    code: string | null;
    teacher: { id: string; name: string; active: boolean } | null;
};

export type CopySources = { options: Option[]; suggested: string | null };

type OpenAction =
    | { kind: 'add' }
    | { kind: 'copy' }
    | { kind: 'teacher'; id: string }
    | { kind: 'remove'; id: string };

export function ClassSubjectsSection({
    classId,
    className,
    subjects,
    subjectOptions,
    teachers,
    copySources,
    openCopy,
    canManage,
}: {
    classId: string;
    className: string;
    subjects: ClassSubjectRow[];
    subjectOptions: Option[];
    teachers: { id: string; name: string }[];
    copySources: CopySources;
    openCopy: boolean;
    canManage: boolean;
}) {
    // Dari halaman akhir tahun (?salin=1) form salin langsung terbuka.
    const [action, setAction] = useState<OpenAction | null>(
        canManage && openCopy ? { kind: 'copy' } : null,
    );
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

    return (
        <section
            id="mapel"
            className="scroll-mt-4 space-y-3 rounded-xl border p-4"
        >
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="flex items-center gap-2 text-base font-medium">
                    <BookOpen className="size-4" />
                    Mata pelajaran ({subjects.length})
                </h2>
                {canManage && (
                    <div className="grid w-full grid-cols-[repeat(auto-fit,minmax(11rem,1fr))] gap-2 sm:flex sm:w-auto sm:flex-wrap">
                        <Button
                            type="button"
                            size="sm"
                            variant={
                                action?.kind === 'add' ? 'default' : 'outline'
                            }
                            onClick={() => toggle({ kind: 'add' })}
                        >
                            <Plus />
                            Tambah mata pelajaran
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant={
                                action?.kind === 'copy' ? 'default' : 'outline'
                            }
                            onClick={() => toggle({ kind: 'copy' })}
                        >
                            <Copy />
                            Salin dari kelas lain
                        </Button>
                    </div>
                )}
            </div>

            {canManage && action?.kind === 'copy' && (
                <CopySubjectsForm
                    classId={classId}
                    className={className}
                    sources={copySources}
                    onDone={close}
                />
            )}

            {canManage && action?.kind === 'add' && (
                <AddSubjectsForm
                    classId={classId}
                    className={className}
                    options={subjectOptions}
                    onDone={close}
                />
            )}

            {subjects.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    Belum ada mata pelajaran di kelas ini.
                </p>
            ) : (
                <ul className="divide-y">
                    {subjects.map((subject) => (
                        <li key={subject.id} className="space-y-2 py-3">
                            <div className="flex flex-wrap items-start justify-between gap-2">
                                <div className="min-w-0">
                                    <p className="font-medium break-words">
                                        {subject.name}
                                        {subject.code && (
                                            <span className="ml-1 text-sm font-normal text-muted-foreground">
                                                ({subject.code})
                                            </span>
                                        )}
                                    </p>
                                    <p className="text-sm">
                                        <span className="text-muted-foreground">
                                            Pengampu:{' '}
                                        </span>
                                        {subject.teacher ? (
                                            subject.teacher.name
                                        ) : (
                                            <span className="text-muted-foreground">
                                                Belum ada
                                            </span>
                                        )}
                                    </p>
                                    {subject.teacher &&
                                        !subject.teacher.active && (
                                            <p className="mt-0.5 flex items-center gap-1 text-xs text-amber-700 dark:text-amber-300">
                                                <CircleAlert className="size-3.5" />
                                                Tidak aktif lagi di lembaga ini
                                            </p>
                                        )}
                                </div>
                                {canManage && (
                                    <div className="grid w-full grid-cols-[repeat(auto-fit,minmax(9rem,1fr))] gap-2 sm:flex sm:w-auto sm:flex-wrap">
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant={
                                                isOpen('teacher', subject.id)
                                                    ? 'default'
                                                    : 'outline'
                                            }
                                            onClick={() =>
                                                toggle({
                                                    kind: 'teacher',
                                                    id: subject.id,
                                                })
                                            }
                                        >
                                            <UserCog />
                                            Ganti pengampu
                                        </Button>
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant={
                                                isOpen('remove', subject.id)
                                                    ? 'destructive'
                                                    : 'outline'
                                            }
                                            onClick={() =>
                                                toggle({
                                                    kind: 'remove',
                                                    id: subject.id,
                                                })
                                            }
                                        >
                                            <Trash2 />
                                            Lepas
                                        </Button>
                                    </div>
                                )}
                            </div>

                            {canManage && isOpen('teacher', subject.id) && (
                                <TeacherForm
                                    subject={subject}
                                    teachers={teachers}
                                    onDone={close}
                                />
                            )}
                            {canManage && isOpen('remove', subject.id) && (
                                <ActionForm
                                    title={`Lepas ${subject.name} dari kelas ${className}`}
                                    action={`/kelas/mapel/${subject.id}`}
                                    method="delete"
                                    errorBag="removeClassSubject"
                                    submitLabel="Lepas mata pelajaran"
                                    destructive
                                    onDone={close}
                                    note="Mata pelajarannya sendiri tetap ada dan bisa ditambahkan lagi."
                                >
                                    {() => null}
                                </ActionForm>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

/**
 * Salin mapel & pengampu dari kelas lain di lembaga yang sama (§18 P3). Saran bawaan: kelas
 * setingkat tahun ajaran sebelumnya. Server yang memilah mana yang disalin dan mana yang dilewati.
 */
function CopySubjectsForm({
    classId,
    className,
    sources,
    onDone,
}: {
    classId: string;
    className: string;
    sources: CopySources;
    onDone: () => void;
}) {
    if (sources.options.length === 0) {
        return (
            <div className="space-y-3 rounded-xl border border-primary/30 bg-muted/20 p-4 text-sm">
                <p>
                    Belum ada kelas lain di lembaga ini yang punya mata
                    pelajaran untuk disalin.
                </p>
                <Button type="button" variant="outline" onClick={onDone}>
                    Tutup
                </Button>
            </div>
        );
    }

    return (
        <ActionForm
            title={`Salin mata pelajaran ke ${className}`}
            action={`/kelas/${classId}/mapel/salin`}
            method="post"
            errorBag="copyClassSubjects"
            submitLabel="Salin mata pelajaran"
            onDone={onDone}
            note="Yang disalin: mata pelajaran aktif yang cocok dengan tingkat kelas ini dan belum ada di kelas, beserta guru pengampunya bila masih bertugas di lembaga ini. Jadwal tidak ikut disalin."
        >
            {(errors) => (
                <Field
                    id={`copy-source-${classId}`}
                    label="Kelas sumber"
                    required
                    error={errors.source_class_id}
                    className="sm:col-span-2"
                >
                    <SelectOptions
                        id={`copy-source-${classId}`}
                        name="source_class_id"
                        options={sources.options}
                        placeholder="Pilih kelas"
                        defaultValue={sources.suggested ?? ''}
                        required
                    />
                    {sources.suggested && (
                        <p className="text-xs text-muted-foreground">
                            Bawaan: kelas setingkat dari tahun ajaran
                            sebelumnya.
                        </p>
                    )}
                </Field>
            )}
        </ActionForm>
    );
}

/** Tambah banyak mapel sekaligus: centang dari mapel aktif yang cocok tingkatnya. */
function AddSubjectsForm({
    classId,
    className,
    options,
    onDone,
}: {
    classId: string;
    className: string;
    options: Option[];
    onDone: () => void;
}) {
    const [selected, setSelected] = useState<string[]>([]);
    const allSelected =
        options.length > 0 && selected.length === options.length;

    const toggleOne = (id: string) =>
        setSelected((current) =>
            current.includes(id)
                ? current.filter((value) => value !== id)
                : [...current, id],
        );

    return (
        <Form
            action={`/kelas/${classId}/mapel`}
            method="post"
            errorBag="addClassSubjects"
            options={{ preserveScroll: true }}
            onSuccess={() => {
                setSelected([]);
                onDone();
            }}
            className="space-y-4 rounded-xl border border-primary/30 bg-muted/20 p-4"
        >
            {({ processing, errors }) => (
                <>
                    <div className="space-y-1">
                        <h3 className="text-base font-medium">
                            Tambah mata pelajaran ke {className}
                        </h3>
                        <p className="text-sm text-muted-foreground">
                            Pilihan: mata pelajaran aktif di lembaga ini yang
                            dipakai di tingkat kelas ini dan belum ada di kelas.
                            Guru pengampu diatur sesudahnya.
                        </p>
                    </div>

                    {options.length === 0 ? (
                        <p className="text-sm">
                            Tidak ada mata pelajaran yang bisa ditambahkan.
                            Tambahkan dulu di menu Mata pelajaran.
                        </p>
                    ) : (
                        <>
                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    className="size-4 accent-primary"
                                    checked={allSelected}
                                    onChange={() =>
                                        setSelected(
                                            allSelected
                                                ? []
                                                : options.map(
                                                      (option) => option.value,
                                                  ),
                                        )
                                    }
                                />
                                Pilih semua ({options.length})
                            </label>
                            <ul className="grid max-h-80 grid-cols-[repeat(auto-fit,minmax(14rem,1fr))] gap-1 overflow-y-auto rounded-md border bg-background p-2">
                                {options.map((option) => (
                                    <li key={option.value}>
                                        <label className="flex cursor-pointer items-start gap-2 rounded p-2 text-sm hover:bg-muted">
                                            <input
                                                type="checkbox"
                                                name="subject_ids[]"
                                                value={option.value}
                                                checked={selected.includes(
                                                    option.value,
                                                )}
                                                onChange={() =>
                                                    toggleOne(option.value)
                                                }
                                                className="mt-0.5 size-4 accent-primary"
                                            />
                                            <span className="break-words">
                                                {option.label}
                                            </span>
                                        </label>
                                    </li>
                                ))}
                            </ul>
                        </>
                    )}

                    <InputError
                        message={
                            errors.subject_ids ??
                            errors['subject_ids.0'] ??
                            errors.form
                        }
                    />

                    <div className="grid grid-cols-[repeat(auto-fit,minmax(9rem,1fr))] gap-2 sm:flex">
                        <Button
                            type="submit"
                            disabled={processing || selected.length === 0}
                        >
                            {processing
                                ? 'Menyimpan…'
                                : `Tambah ${selected.length} mata pelajaran`}
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onDone}
                        >
                            Batal
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

function TeacherForm({
    subject,
    teachers,
    onDone,
}: {
    subject: ClassSubjectRow;
    teachers: { id: string; name: string }[];
    onDone: () => void;
}) {
    // Pengampu lama yang sudah tidak aktif tetap tampil agar tidak terhapus diam-diam.
    const options: Option[] = teachers.map((teacher) => ({
        value: teacher.id,
        label: teacher.name,
    }));

    if (
        subject.teacher &&
        !options.some((option) => option.value === subject.teacher?.id)
    ) {
        options.unshift({
            value: subject.teacher.id,
            label: `${subject.teacher.name} (tidak aktif lagi)`,
        });
    }

    return (
        <ActionForm
            title={`Guru pengampu ${subject.name}`}
            action={`/kelas/mapel/${subject.id}`}
            method="patch"
            errorBag="classSubjectTeacher"
            submitLabel="Simpan pengampu"
            onDone={onDone}
            note="Dipilih dari pegawai aktif yang ditempatkan di lembaga ini."
        >
            {(errors) => (
                <Field
                    id={`cs-teacher-${subject.id}`}
                    label="Guru pengampu"
                    error={errors.teacher_employee_id}
                    className="sm:col-span-2"
                >
                    <SelectOptions
                        id={`cs-teacher-${subject.id}`}
                        name="teacher_employee_id"
                        options={options}
                        placeholder={
                            options.length === 0
                                ? 'Belum ada pegawai aktif di lembaga ini'
                                : 'Belum ditentukan'
                        }
                        defaultValue={subject.teacher?.id ?? ''}
                    />
                </Field>
            )}
        </ActionForm>
    );
}
