import { Form, Head, Link, router } from '@inertiajs/react';
import {
    CircleAlert,
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

type YearOption = Option & { current: boolean; past: boolean };

type ClassRow = {
    id: string;
    name: string;
    grade: number;
    is_active: boolean;
    homeroom: { id: string; name: string; active: boolean } | null;
    members: { total: number; male: number; female: number };
    has_history: boolean;
};

type Props = {
    filters: { lembaga: string | null; tahun: string | null };
    options: { organizations: Option[]; years: YearOption[] };
    year: YearOption | null;
    classes: ClassRow[];
    canManage: boolean;
    homerooms: { id: string; name: string }[];
};

type OpenAction =
    | { kind: 'create' }
    | { kind: 'edit'; id: string }
    | { kind: 'delete'; id: string };

const BASE_URL = '/kelas';

const GRADE_OPTIONS: Option[] = Array.from({ length: 12 }, (_, index) => ({
    value: String(index + 1),
    label: `Tingkat ${index + 1}`,
}));

/**
 * Daftar kelas per lembaga & tahun ajaran (PRD-002 §6, AC-1d-a). Kelas tahun ajaran yang sudah
 * lewat hanya bisa dilihat; aksi hanya untuk pemegang izin kelola kelas di lembaga itu.
 */
export default function Classes({
    filters,
    options,
    year,
    classes,
    canManage,
    homerooms,
}: Props) {
    const [action, setAction] = useState<OpenAction | null>(null);
    const close = () => setAction(null);
    const isOpen = (kind: OpenAction['kind'], id?: string) =>
        action !== null &&
        action.kind === kind &&
        (id === undefined || ('id' in action && action.id === id));

    const changeFilter = (next: { lembaga?: string; tahun?: string }) => {
        setAction(null);
        router.get(
            BASE_URL,
            {
                lembaga: next.lembaga ?? filters.lembaga ?? undefined,
                // Ganti lembaga → tahun ajaran mengikuti kalender lembaga baru (bawaan: tahun berjalan).
                tahun: next.lembaga
                    ? undefined
                    : (next.tahun ?? filters.tahun ?? undefined),
            },
            { preserveState: true, replace: true },
        );
    };

    const totals = classes.reduce(
        (sum, item) => ({
            total: sum.total + item.members.total,
            male: sum.male + item.members.male,
            female: sum.female + item.members.female,
        }),
        { total: 0, male: 0, female: 0 },
    );

    return (
        <>
            <Head title="Kelas" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <Heading
                    title="Kelas"
                    description="Kelas/rombel per lembaga dan tahun ajaran."
                />

                {options.organizations.length === 0 ? (
                    <EmptyState text="Belum ada lembaga yang bisa Anda lihat dari lembaga kerja ini." />
                ) : (
                    <>
                        {/* Grid otomatis: saringan turun baris di layar sempit. */}
                        <div className="grid grid-cols-[repeat(auto-fit,minmax(14rem,1fr))] gap-3 rounded-xl border p-4">
                            <div className="grid gap-1.5">
                                <Label htmlFor="f-lembaga">Lembaga</Label>
                                <select
                                    id="f-lembaga"
                                    className={selectClass}
                                    value={filters.lembaga ?? ''}
                                    onChange={(event) =>
                                        changeFilter({
                                            lembaga: event.target.value,
                                        })
                                    }
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
                            <div className="grid gap-1.5">
                                <Label htmlFor="f-tahun">Tahun ajaran</Label>
                                <select
                                    id="f-tahun"
                                    className={selectClass}
                                    value={filters.tahun ?? ''}
                                    disabled={options.years.length === 0}
                                    onChange={(event) =>
                                        changeFilter({
                                            tahun: event.target.value,
                                        })
                                    }
                                >
                                    {options.years.length === 0 && (
                                        <option value="">
                                            Belum ada tahun ajaran
                                        </option>
                                    )}
                                    {options.years.map((option) => (
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

                        {year === null ? (
                            <EmptyState text="Kalender yang dipakai lembaga ini belum punya tahun ajaran. Minta Admin Yayasan menambahkannya di menu Kalender akademik." />
                        ) : (
                            <>
                                {year.past && (
                                    <p className="flex gap-2 rounded-md border border-amber-500/50 bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
                                        <CircleAlert className="mt-0.5 size-4 shrink-0" />
                                        Tahun ajaran ini sudah lewat. Kelasnya
                                        hanya bisa dilihat.
                                    </p>
                                )}

                                {canManage && (
                                    <div className="space-y-3">
                                        <Button
                                            type="button"
                                            variant={
                                                isOpen('create')
                                                    ? 'default'
                                                    : 'outline'
                                            }
                                            onClick={() =>
                                                setAction(
                                                    isOpen('create')
                                                        ? null
                                                        : { kind: 'create' },
                                                )
                                            }
                                            className="w-full sm:w-auto"
                                        >
                                            <Plus />
                                            Tambah kelas
                                        </Button>
                                        {isOpen('create') && (
                                            <ClassForm
                                                organizationId={
                                                    filters.lembaga ?? ''
                                                }
                                                yearId={year.value}
                                                homerooms={homerooms}
                                                onDone={close}
                                            />
                                        )}
                                    </div>
                                )}

                                {classes.length === 0 ? (
                                    <EmptyState text="Belum ada kelas di tahun ajaran ini." />
                                ) : (
                                    <section className="rounded-xl border">
                                        <div className="hidden grid-cols-[2fr_1fr_2fr_1.5fr] gap-3 border-b px-4 py-2 text-xs font-medium text-muted-foreground sm:grid">
                                            <span>Kelas</span>
                                            <span>Tingkat</span>
                                            <span>Wali kelas</span>
                                            <span>Siswa</span>
                                        </div>
                                        <ul className="divide-y">
                                            {classes.map((item) => (
                                                <ClassItem
                                                    key={item.id}
                                                    item={item}
                                                    canManage={canManage}
                                                    homerooms={homerooms}
                                                    isOpen={isOpen}
                                                    onToggle={(next) =>
                                                        setAction((current) =>
                                                            current &&
                                                            current.kind ===
                                                                next.kind &&
                                                            'id' in current &&
                                                            'id' in next &&
                                                            current.id ===
                                                                next.id
                                                                ? null
                                                                : next,
                                                        )
                                                    }
                                                    onDone={close}
                                                />
                                            ))}
                                        </ul>
                                        <p className="border-t px-4 py-2 text-sm text-muted-foreground">
                                            {classes.length} kelas ·{' '}
                                            {totals.total} siswa (L{' '}
                                            {totals.male} · P {totals.female})
                                        </p>
                                    </section>
                                )}
                            </>
                        )}
                    </>
                )}
            </div>
        </>
    );
}

function ClassItem({
    item,
    canManage,
    homerooms,
    isOpen,
    onToggle,
    onDone,
}: {
    item: ClassRow;
    canManage: boolean;
    homerooms: { id: string; name: string }[];
    isOpen: (kind: OpenAction['kind'], id?: string) => boolean;
    onToggle: (next: OpenAction) => void;
    onDone: () => void;
}) {
    return (
        <li className="space-y-3 px-4 py-3">
            <div className="grid gap-1 sm:grid-cols-[2fr_1fr_2fr_1.5fr] sm:items-center sm:gap-3">
                <div className="flex flex-wrap items-center gap-1.5">
                    <Link
                        href={`${BASE_URL}/${item.id}`}
                        className="font-medium hover:underline"
                    >
                        {item.name}
                    </Link>
                    {!item.is_active && (
                        <Badge variant="outline">Nonaktif</Badge>
                    )}
                </div>
                <span className="text-sm">
                    <span className="text-muted-foreground sm:hidden">
                        Tingkat{' '}
                    </span>
                    {item.grade}
                </span>
                <span className="text-sm">
                    <span className="text-muted-foreground sm:hidden">
                        Wali kelas:{' '}
                    </span>
                    {item.homeroom ? (
                        <>
                            {item.homeroom.name}
                            {!item.homeroom.active && (
                                <span className="mt-0.5 flex items-center gap-1 text-xs text-amber-700 dark:text-amber-300">
                                    <CircleAlert className="size-3.5" />
                                    Tidak aktif lagi di lembaga ini
                                </span>
                            )}
                        </>
                    ) : (
                        <span className="text-muted-foreground">Belum ada</span>
                    )}
                </span>
                <span className="text-sm">
                    {item.members.total} siswa
                    <span className="text-muted-foreground">
                        {' '}
                        (L {item.members.male} · P {item.members.female})
                    </span>
                </span>
            </div>

            {canManage && (
                <div className="grid grid-cols-[repeat(auto-fit,minmax(9rem,1fr))] gap-2 sm:flex sm:flex-wrap">
                    <Button
                        type="button"
                        size="sm"
                        variant={
                            isOpen('edit', item.id) ? 'default' : 'outline'
                        }
                        onClick={() => onToggle({ kind: 'edit', id: item.id })}
                    >
                        <Pencil />
                        Ubah
                    </Button>
                    <StatusButton item={item} />
                    {!item.has_history && (
                        <Button
                            type="button"
                            size="sm"
                            variant={
                                isOpen('delete', item.id)
                                    ? 'destructive'
                                    : 'outline'
                            }
                            onClick={() =>
                                onToggle({ kind: 'delete', id: item.id })
                            }
                        >
                            <Trash2 />
                            Hapus
                        </Button>
                    )}
                </div>
            )}

            {canManage && isOpen('edit', item.id) && (
                <ClassForm item={item} homerooms={homerooms} onDone={onDone} />
            )}
            {canManage && isOpen('delete', item.id) && (
                <ActionForm
                    title={`Hapus kelas ${item.name}`}
                    action={`${BASE_URL}/${item.id}`}
                    method="delete"
                    errorBag="deleteClass"
                    submitLabel="Hapus kelas"
                    destructive
                    onDone={onDone}
                    note="Kelas ini belum pernah punya anggota, jadi boleh dihapus. Tindakan ini tidak bisa dibatalkan."
                >
                    {() => null}
                </ActionForm>
            )}
        </li>
    );
}

/** Tambah kelas (tanpa `item`) atau ubah kelas. */
function ClassForm({
    item,
    organizationId,
    yearId,
    homerooms,
    onDone,
}: {
    item?: ClassRow;
    organizationId?: string;
    yearId?: string;
    homerooms: { id: string; name: string }[];
    onDone: () => void;
}) {
    // Wali kelas lama yang sudah tidak aktif tetap tampil sebagai pilihan agar tidak terhapus diam-diam.
    const homeroomOptions: Option[] = homerooms.map((employee) => ({
        value: employee.id,
        label: employee.name,
    }));

    if (
        item?.homeroom &&
        !homeroomOptions.some((option) => option.value === item.homeroom?.id)
    ) {
        homeroomOptions.unshift({
            value: item.homeroom.id,
            label: `${item.homeroom.name} (tidak aktif lagi)`,
        });
    }

    return (
        <ActionForm
            title={item ? `Ubah kelas ${item.name}` : 'Tambah kelas'}
            action={item ? `${BASE_URL}/${item.id}` : BASE_URL}
            method={item ? 'patch' : 'post'}
            errorBag={item ? 'editClass' : 'createClass'}
            submitLabel={item ? 'Simpan kelas' : 'Tambah kelas'}
            onDone={onDone}
            note="Wali kelas dipilih dari pegawai aktif yang ditempatkan di lembaga ini. Menjadi wali kelas tidak memberi hak akses apa pun."
        >
            {(errors) => (
                <>
                    {!item && (
                        <>
                            <input
                                type="hidden"
                                name="organization_id"
                                value={organizationId}
                            />
                            <input
                                type="hidden"
                                name="academic_year_id"
                                value={yearId}
                            />
                            <InputError
                                message={
                                    errors.organization_id ??
                                    errors.academic_year_id
                                }
                                className="sm:col-span-2"
                            />
                        </>
                    )}
                    <Field
                        id={`c-name-${item?.id ?? 'new'}`}
                        label="Nama kelas"
                        required
                        error={errors.name}
                    >
                        <Input
                            id={`c-name-${item?.id ?? 'new'}`}
                            name="name"
                            defaultValue={item?.name ?? ''}
                            maxLength={50}
                            placeholder="Mis. VII-A atau Ula 2 Putri"
                            required
                        />
                    </Field>
                    <Field
                        id={`c-grade-${item?.id ?? 'new'}`}
                        label="Tingkat"
                        required
                        error={errors.grade}
                    >
                        <SelectOptions
                            id={`c-grade-${item?.id ?? 'new'}`}
                            name="grade"
                            options={GRADE_OPTIONS}
                            placeholder="Pilih tingkat"
                            defaultValue={item ? String(item.grade) : ''}
                            required
                        />
                    </Field>
                    <Field
                        id={`c-homeroom-${item?.id ?? 'new'}`}
                        label="Wali kelas"
                        error={errors.homeroom_employee_id}
                        className="sm:col-span-2"
                    >
                        <SelectOptions
                            id={`c-homeroom-${item?.id ?? 'new'}`}
                            name="homeroom_employee_id"
                            options={homeroomOptions}
                            placeholder={
                                homeroomOptions.length === 0
                                    ? 'Belum ada pegawai aktif di lembaga ini'
                                    : 'Belum ditentukan'
                            }
                            defaultValue={item?.homeroom?.id ?? ''}
                        />
                    </Field>
                </>
            )}
        </ActionForm>
    );
}

function StatusButton({ item }: { item: ClassRow }) {
    return (
        <Form
            action={`${BASE_URL}/${item.id}/status`}
            method="post"
            errorBag="classStatus"
            options={{ preserveScroll: true }}
        >
            {({ processing, errors }) => (
                <>
                    <input
                        type="hidden"
                        name="active"
                        value={item.is_active ? '0' : '1'}
                    />
                    <Button
                        type="submit"
                        size="sm"
                        variant="outline"
                        disabled={processing}
                        className="w-full"
                    >
                        {item.is_active ? <PowerOff /> : <Power />}
                        {item.is_active ? 'Nonaktifkan' : 'Aktifkan kembali'}
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
