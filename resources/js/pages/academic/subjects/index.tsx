import { Form, Head, router } from '@inertiajs/react';
import { Pencil, Plus, Power, PowerOff, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { Option } from '@/components/academic/student-actions';
import {
    ActionForm,
    Field,
    SelectOptions,
    selectClass,
} from '@/components/academic/student-actions';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type SubjectRow = {
    id: string;
    name: string;
    code: string | null;
    grade_min: number | null;
    grade_max: number | null;
    grades: string;
    is_active: boolean;
    deletable: boolean;
};

type Props = {
    filters: { lembaga: string | null };
    options: { organizations: Option[] };
    subjects: SubjectRow[];
    canManage: boolean;
};

type OpenAction =
    | { kind: 'create' }
    | { kind: 'edit'; id: string }
    | { kind: 'delete'; id: string };

const BASE_URL = '/akademik/mapel';

const GRADE_OPTIONS: Option[] = Array.from({ length: 12 }, (_, index) => ({
    value: String(index + 1),
    label: String(index + 1),
}));

/**
 * Mata pelajaran per lembaga (PRD-002 §16 AC-3a). Aksi hanya untuk pemegang izin kelola kelas di
 * lembaga itu; semua aturan tetap dicek server.
 */
export default function Subjects({
    filters,
    options,
    subjects,
    canManage,
}: Props) {
    const [action, setAction] = useState<OpenAction | null>(null);
    const close = () => setAction(null);
    const isOpen = (kind: OpenAction['kind'], id?: string) =>
        action !== null &&
        action.kind === kind &&
        (id === undefined || ('id' in action && action.id === id));
    const toggle = (next: OpenAction) =>
        setAction((current) =>
            current &&
            current.kind === next.kind &&
            ('id' in current ? current.id : '') ===
                ('id' in next ? next.id : '')
                ? null
                : next,
        );

    const active = subjects.filter((subject) => subject.is_active).length;

    return (
        <>
            <Head title="Mata pelajaran" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <Heading
                    title="Mata pelajaran"
                    description="Mata pelajaran per lembaga beserta tingkat yang memakainya."
                />

                {options.organizations.length === 0 ? (
                    <EmptyState text="Belum ada lembaga yang bisa Anda lihat dari lembaga kerja ini." />
                ) : (
                    <>
                        <div className="grid grid-cols-[repeat(auto-fit,minmax(14rem,1fr))] gap-3 rounded-xl border p-4">
                            <div className="grid gap-1.5">
                                <Label htmlFor="f-lembaga">Lembaga</Label>
                                <select
                                    id="f-lembaga"
                                    className={selectClass}
                                    value={filters.lembaga ?? ''}
                                    onChange={(event) => {
                                        setAction(null);
                                        router.get(
                                            BASE_URL,
                                            { lembaga: event.target.value },
                                            {
                                                preserveState: true,
                                                replace: true,
                                            },
                                        );
                                    }}
                                >
                                    {options.organizations.map((option) => (
                                        <option
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        </div>

                        {canManage && (
                            <div className="space-y-3">
                                <Button
                                    type="button"
                                    variant={
                                        isOpen('create') ? 'default' : 'outline'
                                    }
                                    onClick={() => toggle({ kind: 'create' })}
                                    className="w-full sm:w-auto"
                                >
                                    <Plus />
                                    Tambah mata pelajaran
                                </Button>
                                {isOpen('create') && (
                                    <SubjectForm
                                        organizationId={filters.lembaga ?? ''}
                                        onDone={close}
                                    />
                                )}
                            </div>
                        )}

                        {subjects.length === 0 ? (
                            <EmptyState text="Belum ada mata pelajaran di lembaga ini." />
                        ) : (
                            <section className="rounded-xl border">
                                <div className="hidden grid-cols-[3fr_1fr_2fr] gap-3 border-b px-4 py-2 text-xs font-medium text-muted-foreground sm:grid">
                                    <span>Mata pelajaran</span>
                                    <span>Kode</span>
                                    <span>Tingkat</span>
                                </div>
                                <ul className="divide-y">
                                    {subjects.map((subject) => (
                                        <li
                                            key={subject.id}
                                            className="space-y-3 px-4 py-3"
                                        >
                                            <div className="grid gap-1 sm:grid-cols-[3fr_1fr_2fr] sm:items-center sm:gap-3">
                                                <div className="flex flex-wrap items-center gap-1.5">
                                                    <span className="font-medium break-words">
                                                        {subject.name}
                                                    </span>
                                                    {!subject.is_active && (
                                                        <Badge variant="outline">
                                                            Nonaktif
                                                        </Badge>
                                                    )}
                                                </div>
                                                <span className="text-sm">
                                                    <span className="text-muted-foreground sm:hidden">
                                                        Kode:{' '}
                                                    </span>
                                                    {subject.code ?? (
                                                        <span className="text-muted-foreground">
                                                            —
                                                        </span>
                                                    )}
                                                </span>
                                                <span className="text-sm">
                                                    {subject.grades}
                                                </span>
                                            </div>

                                            {canManage && (
                                                <div className="grid grid-cols-[repeat(auto-fit,minmax(9rem,1fr))] gap-2 sm:flex sm:flex-wrap">
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant={
                                                            isOpen(
                                                                'edit',
                                                                subject.id,
                                                            )
                                                                ? 'default'
                                                                : 'outline'
                                                        }
                                                        onClick={() =>
                                                            toggle({
                                                                kind: 'edit',
                                                                id: subject.id,
                                                            })
                                                        }
                                                    >
                                                        <Pencil />
                                                        Ubah
                                                    </Button>
                                                    <StatusButton
                                                        subject={subject}
                                                    />
                                                    {subject.deletable && (
                                                        <Button
                                                            type="button"
                                                            size="sm"
                                                            variant={
                                                                isOpen(
                                                                    'delete',
                                                                    subject.id,
                                                                )
                                                                    ? 'destructive'
                                                                    : 'outline'
                                                            }
                                                            onClick={() =>
                                                                toggle({
                                                                    kind: 'delete',
                                                                    id: subject.id,
                                                                })
                                                            }
                                                        >
                                                            <Trash2 />
                                                            Hapus
                                                        </Button>
                                                    )}
                                                </div>
                                            )}

                                            {canManage &&
                                                isOpen('edit', subject.id) && (
                                                    <SubjectForm
                                                        subject={subject}
                                                        onDone={close}
                                                    />
                                                )}
                                            {canManage &&
                                                isOpen(
                                                    'delete',
                                                    subject.id,
                                                ) && (
                                                    <ActionForm
                                                        title={`Hapus ${subject.name}`}
                                                        action={`${BASE_URL}/${subject.id}`}
                                                        method="delete"
                                                        errorBag="deleteSubject"
                                                        submitLabel="Hapus mata pelajaran"
                                                        destructive
                                                        onDone={close}
                                                        note="Mata pelajaran ini belum dipakai di kelas, jadi boleh dihapus. Tindakan ini tidak bisa dibatalkan."
                                                    >
                                                        {() => null}
                                                    </ActionForm>
                                                )}
                                        </li>
                                    ))}
                                </ul>
                                <p className="border-t px-4 py-2 text-sm text-muted-foreground">
                                    {subjects.length} mata pelajaran · {active}{' '}
                                    aktif
                                </p>
                            </section>
                        )}
                    </>
                )}
            </div>
        </>
    );
}

/** Tambah mata pelajaran (tanpa `subject`) atau ubah. */
function SubjectForm({
    subject,
    organizationId,
    onDone,
}: {
    subject?: SubjectRow;
    organizationId?: string;
    onDone: () => void;
}) {
    const key = subject?.id ?? 'new';

    return (
        <ActionForm
            title={subject ? `Ubah ${subject.name}` : 'Tambah mata pelajaran'}
            action={subject ? `${BASE_URL}/${subject.id}` : BASE_URL}
            method={subject ? 'patch' : 'post'}
            errorBag={subject ? 'editSubject' : 'createSubject'}
            submitLabel={
                subject ? 'Simpan mata pelajaran' : 'Tambah mata pelajaran'
            }
            onDone={onDone}
            note="Kosongkan tingkat bila mata pelajaran dipakai di semua tingkat."
        >
            {(errors) => (
                <>
                    {!subject && (
                        <>
                            <input
                                type="hidden"
                                name="organization_id"
                                value={organizationId}
                            />
                            <InputError
                                message={errors.organization_id}
                                className="sm:col-span-2"
                            />
                        </>
                    )}
                    <Field
                        id={`s-name-${key}`}
                        label="Nama mata pelajaran"
                        required
                        error={errors.name}
                    >
                        <Input
                            id={`s-name-${key}`}
                            name="name"
                            defaultValue={subject?.name ?? ''}
                            maxLength={100}
                            placeholder="Mis. Matematika atau Fiqih"
                            required
                        />
                    </Field>
                    <Field
                        id={`s-code-${key}`}
                        label="Kode"
                        error={errors.code}
                    >
                        <Input
                            id={`s-code-${key}`}
                            name="code"
                            defaultValue={subject?.code ?? ''}
                            maxLength={20}
                            placeholder="Opsional, mis. MTK"
                        />
                    </Field>
                    <Field
                        id={`s-min-${key}`}
                        label="Tingkat dari"
                        error={errors.grade_min}
                    >
                        <SelectOptions
                            id={`s-min-${key}`}
                            name="grade_min"
                            options={GRADE_OPTIONS}
                            placeholder="Semua tingkat"
                            defaultValue={
                                subject?.grade_min
                                    ? String(subject.grade_min)
                                    : ''
                            }
                        />
                    </Field>
                    <Field
                        id={`s-max-${key}`}
                        label="Tingkat sampai"
                        error={errors.grade_max}
                    >
                        <SelectOptions
                            id={`s-max-${key}`}
                            name="grade_max"
                            options={GRADE_OPTIONS}
                            placeholder="Semua tingkat"
                            defaultValue={
                                subject?.grade_max
                                    ? String(subject.grade_max)
                                    : ''
                            }
                        />
                    </Field>
                </>
            )}
        </ActionForm>
    );
}

function StatusButton({ subject }: { subject: SubjectRow }) {
    return (
        <Form
            action={`${BASE_URL}/${subject.id}/status`}
            method="post"
            errorBag="subjectStatus"
            options={{ preserveScroll: true }}
        >
            {({ processing, errors }) => (
                <>
                    <input
                        type="hidden"
                        name="active"
                        value={subject.is_active ? '0' : '1'}
                    />
                    <Button
                        type="submit"
                        size="sm"
                        variant="outline"
                        disabled={processing}
                        className="w-full"
                    >
                        {subject.is_active ? <PowerOff /> : <Power />}
                        {subject.is_active ? 'Nonaktifkan' : 'Aktifkan kembali'}
                    </Button>
                    <InputError message={errors.form} />
                </>
            )}
        </Form>
    );
}

function EmptyState({ text }: { text: string }) {
    return (
        <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
            {text}
        </p>
    );
}
