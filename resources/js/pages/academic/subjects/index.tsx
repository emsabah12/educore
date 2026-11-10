import { Form, Head, router } from '@inertiajs/react';
import {
    FolderTree,
    Pencil,
    Plus,
    Power,
    PowerOff,
    Trash2,
} from 'lucide-react';
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
    grading_aspects: string[] | null;
    group_id: string | null;
    group: string | null;
    sort_order: number | null;
    grade_type: 'NUMBER' | 'LETTER';
};

type Aspect = { code: string; name: string };

type GroupRow = {
    id: string;
    name: string;
    sort_order: number;
    counts_in_totals: boolean;
    subject_count: number;
};

type Props = {
    filters: { lembaga: string | null };
    options: { organizations: Option[] };
    subjects: SubjectRow[];
    canManage: boolean;
    /** Aspek penilaian lembaga (AC-8a); kosong bila lembaga hanya punya satu aspek. */
    aspects: Aspect[];
    /** Kelompok mapel lembaga (AC-8b). */
    groups: GroupRow[];
};

type OpenAction =
    | { kind: 'create' }
    | { kind: 'edit'; id: string }
    | { kind: 'delete'; id: string }
    | { kind: 'group-create' }
    | { kind: 'group-edit'; id: string }
    | { kind: 'group-delete'; id: string };

const BASE_URL = '/akademik/mapel';

const GRADE_OPTIONS: Option[] = Array.from({ length: 12 }, (_, index) => ({
    value: String(index + 1),
    label: String(index + 1),
}));

/**
 * Mata pelajaran per lembaga (PRD-002 §16 AC-3a). Aksi hanya untuk pemegang izin kelola kelas di
 * lembaga itu; semua aturan tetap dicek server. Lembaga beraspek: tiap mapel memilih aspek yang
 * dinilai (§22 AC-8a). Kelompok mapel, urutan & jenis nilai angka/huruf (§22.4 AC-8b).
 */
export default function Subjects({
    filters,
    options,
    subjects,
    canManage,
    aspects,
    groups,
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
                                        aspects={aspects}
                                        groups={groups}
                                        onDone={close}
                                    />
                                )}
                            </div>
                        )}

                        <GroupSection
                            groups={groups}
                            organizationId={filters.lembaga ?? ''}
                            canManage={canManage}
                            isOpen={isOpen}
                            toggle={toggle}
                            close={close}
                        />

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
                                                    {subject.grade_type ===
                                                        'LETTER' && (
                                                        <Badge variant="secondary">
                                                            Nilai huruf
                                                        </Badge>
                                                    )}
                                                    {subject.group && (
                                                        <span className="w-full text-xs text-muted-foreground">
                                                            {subject.group}
                                                            {subject.sort_order !==
                                                                null &&
                                                                ` · urutan ${subject.sort_order}`}
                                                        </span>
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
                                                    {aspects.length > 1 && (
                                                        <span className="block text-xs text-muted-foreground">
                                                            Dinilai:{' '}
                                                            {aspects
                                                                .filter(
                                                                    (aspect) =>
                                                                        subject.grading_aspects ===
                                                                            null ||
                                                                        subject.grading_aspects.includes(
                                                                            aspect.code,
                                                                        ),
                                                                )
                                                                .map(
                                                                    (aspect) =>
                                                                        aspect.name,
                                                                )
                                                                .join(', ')}
                                                        </span>
                                                    )}
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
                                                        aspects={aspects}
                                                        groups={groups}
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
    aspects,
    groups,
    onDone,
}: {
    subject?: SubjectRow;
    organizationId?: string;
    aspects: Aspect[];
    groups: GroupRow[];
    onDone: () => void;
}) {
    const chosen = subject?.grading_aspects ?? null;
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
                    <Field
                        id={`s-group-${key}`}
                        label="Kelompok"
                        error={errors.group_id}
                    >
                        <SelectOptions
                            id={`s-group-${key}`}
                            name="group_id"
                            options={groups.map((group) => ({
                                value: group.id,
                                label: group.name,
                            }))}
                            placeholder={
                                groups.length === 0
                                    ? 'Belum ada kelompok'
                                    : 'Tanpa kelompok'
                            }
                            defaultValue={subject?.group_id ?? ''}
                        />
                    </Field>
                    <Field
                        id={`s-order-${key}`}
                        label="Urutan di rapor"
                        error={errors.sort_order}
                    >
                        <Input
                            id={`s-order-${key}`}
                            name="sort_order"
                            type="number"
                            min={0}
                            max={999}
                            step={1}
                            defaultValue={subject?.sort_order ?? ''}
                            placeholder="Opsional, mis. 1"
                        />
                    </Field>
                    <Field
                        id={`s-type-${key}`}
                        label="Jenis nilai"
                        error={errors.grade_type}
                    >
                        <select
                            id={`s-type-${key}`}
                            name="grade_type"
                            className={selectClass}
                            defaultValue={subject?.grade_type ?? 'NUMBER'}
                        >
                            <option value="NUMBER">Angka 0–100</option>
                            <option value="LETTER">
                                Huruf (pilih dari skala predikat)
                            </option>
                        </select>
                    </Field>
                    {aspects.length > 1 && (
                        <fieldset className="grid gap-1.5 sm:col-span-2">
                            <legend className="mb-1.5 text-sm font-medium">
                                Aspek yang dinilai
                            </legend>
                            <input
                                type="hidden"
                                name="grading_aspects_set"
                                value="1"
                            />
                            <div className="flex flex-wrap gap-x-4 gap-y-2">
                                {aspects.map((aspect) => (
                                    <label
                                        key={aspect.code}
                                        className="flex min-h-10 cursor-pointer items-center gap-2 text-sm"
                                    >
                                        <input
                                            type="checkbox"
                                            name="grading_aspects[]"
                                            value={aspect.code}
                                            defaultChecked={
                                                chosen === null ||
                                                chosen.includes(aspect.code)
                                            }
                                            className="size-4"
                                        />
                                        {aspect.name}
                                    </label>
                                ))}
                            </div>
                            <p className="text-xs text-muted-foreground">
                                Mis. mapel yang hanya diuji praktik: centang
                                aspek praktik saja. Nilai akhir = rata-rata
                                aspek yang dicentang.
                            </p>
                            <InputError message={errors.grading_aspects} />
                        </fieldset>
                    )}
                </>
            )}
        </ActionForm>
    );
}

/**
 * Kelompok mapel lembaga (AC-8b): urutan & penomoran di rapor, ikut jumlah & rata-rata atau tidak.
 */
function GroupSection({
    groups,
    organizationId,
    canManage,
    isOpen,
    toggle,
    close,
}: {
    groups: GroupRow[];
    organizationId: string;
    canManage: boolean;
    isOpen: (kind: OpenAction['kind'], id?: string) => boolean;
    toggle: (next: OpenAction) => void;
    close: () => void;
}) {
    if (groups.length === 0 && !canManage) {
        return null;
    }

    return (
        <section className="space-y-3 rounded-xl border p-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="space-y-1">
                    <h2 className="flex items-center gap-2 text-base font-medium">
                        <FolderTree className="size-4 text-primary" />
                        Kelompok mapel
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        Mis. Pelajaran Madrasah, Pelajaran Kepesantrenan. Rapor
                        menomori kelompok (1, 2, 3) dan mapel di dalamnya (a, b,
                        c). Tanpa kelompok, mapel urut nama.
                    </p>
                </div>
                {canManage && (
                    <Button
                        type="button"
                        size="sm"
                        variant={isOpen('group-create') ? 'default' : 'outline'}
                        onClick={() => toggle({ kind: 'group-create' })}
                    >
                        <Plus />
                        Tambah kelompok
                    </Button>
                )}
            </div>

            {isOpen('group-create') && (
                <GroupForm organizationId={organizationId} onDone={close} />
            )}

            {groups.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    Belum ada kelompok.
                </p>
            ) : (
                <ul className="divide-y rounded-lg border">
                    {groups.map((group, index) => (
                        <li key={group.id} className="space-y-3 px-3 py-2.5">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <div className="min-w-0">
                                    <p className="font-medium">
                                        {index + 1}. {group.name}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        Urutan {group.sort_order} ·{' '}
                                        {group.subject_count} mapel ·{' '}
                                        {group.counts_in_totals
                                            ? 'ikut jumlah & rata-rata rapor'
                                            : 'tidak ikut jumlah & rata-rata rapor'}
                                    </p>
                                </div>
                                {canManage && (
                                    <div className="flex gap-2">
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                toggle({
                                                    kind: 'group-edit',
                                                    id: group.id,
                                                })
                                            }
                                        >
                                            <Pencil />
                                            Ubah
                                        </Button>
                                        {group.subject_count === 0 && (
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant={
                                                    isOpen(
                                                        'group-delete',
                                                        group.id,
                                                    )
                                                        ? 'destructive'
                                                        : 'outline'
                                                }
                                                onClick={() =>
                                                    toggle({
                                                        kind: 'group-delete',
                                                        id: group.id,
                                                    })
                                                }
                                            >
                                                <Trash2 />
                                                Hapus
                                            </Button>
                                        )}
                                    </div>
                                )}
                            </div>
                            {isOpen('group-edit', group.id) && (
                                <GroupForm group={group} onDone={close} />
                            )}
                            {isOpen('group-delete', group.id) && (
                                <ActionForm
                                    title={`Hapus kelompok ${group.name}`}
                                    action={`${BASE_URL}/kelompok/${group.id}`}
                                    method="delete"
                                    errorBag="deleteGroup"
                                    submitLabel="Hapus kelompok"
                                    destructive
                                    onDone={close}
                                    note="Kelompok ini belum dipakai mata pelajaran."
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

/** Tambah kelompok (tanpa `group`) atau ubah. */
function GroupForm({
    group,
    organizationId,
    onDone,
}: {
    group?: GroupRow;
    organizationId?: string;
    onDone: () => void;
}) {
    const key = group?.id ?? 'new';

    return (
        <ActionForm
            title={group ? `Ubah ${group.name}` : 'Tambah kelompok mapel'}
            action={
                group
                    ? `${BASE_URL}/kelompok/${group.id}`
                    : `${BASE_URL}/kelompok`
            }
            method={group ? 'patch' : 'post'}
            errorBag={group ? 'editGroup' : 'createGroup'}
            submitLabel={group ? 'Simpan kelompok' : 'Tambah kelompok'}
            onDone={onDone}
        >
            {(errors) => (
                <>
                    {!group && (
                        <input
                            type="hidden"
                            name="organization_id"
                            value={organizationId}
                        />
                    )}
                    <Field
                        id={`g-name-${key}`}
                        label="Nama kelompok"
                        required
                        error={errors.name}
                    >
                        <Input
                            id={`g-name-${key}`}
                            name="name"
                            defaultValue={group?.name ?? ''}
                            maxLength={60}
                            placeholder="Mis. Pelajaran Madrasah"
                            required
                        />
                    </Field>
                    <Field
                        id={`g-order-${key}`}
                        label="Urutan"
                        error={errors.sort_order}
                    >
                        <Input
                            id={`g-order-${key}`}
                            name="sort_order"
                            type="number"
                            min={0}
                            max={999}
                            step={1}
                            defaultValue={group?.sort_order ?? 1}
                        />
                    </Field>
                    <label className="flex min-h-10 cursor-pointer items-center gap-2 text-sm sm:col-span-2">
                        <input
                            type="checkbox"
                            name="counts_in_totals"
                            value="1"
                            defaultChecked={group?.counts_in_totals ?? true}
                            className="size-4"
                        />
                        Nilai mapel di kelompok ini ikut jumlah & rata-rata
                        rapor
                    </label>
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
