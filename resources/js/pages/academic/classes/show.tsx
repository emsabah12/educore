import { Form, Head, Link } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRightLeft,
    CalendarCheck,
    CircleAlert,
    LogOut,
    UserPlus,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    MoveMemberForm,
    RemoveMemberForm,
} from '@/components/academic/class-member-actions';
import type { Option } from '@/components/academic/student-actions';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Member = {
    id: string;
    student_id: string;
    name: string;
    gender: string | null;
    nisn: string | null;
    student_number: string | null;
    start_date: string;
    end_date: string | null;
};

type Candidate = {
    enrollment_id: string;
    student_id: string;
    name: string;
    gender: string | null;
    nisn: string | null;
    student_number: string | null;
};

type Props = {
    class: {
        id: string;
        name: string;
        grade: number;
        is_active: boolean;
        organization: string;
        homeroom: { id: string; name: string; active: boolean } | null;
    };
    year: {
        id: string;
        name: string;
        current: boolean;
        past: boolean;
        starts_in_future: boolean;
        start_date: string;
        end_date: string;
    };
    members: Member[];
    former: Member[];
    moveTargets: Option[];
    canManage: boolean;
    canYearEnd: boolean;
    candidates: Candidate[];
    today: string;
    maxPerSave: number;
};

type OpenAction =
    | { kind: 'add' }
    | { kind: 'move'; id: string }
    | { kind: 'remove'; id: string };

const dateFormat = new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium' });

function formatDate(value: string): string {
    const [year, month, day] = value.slice(0, 10).split('-').map(Number);

    return dateFormat.format(new Date(year, month - 1, day));
}

/**
 * Detail kelas (PRD-002 §6, AC-1d-b): anggota, masukkan siswa (banyak sekaligus), pindah, keluarkan.
 */
export default function ClassDetail(props: Props) {
    const { class: item, year, members, former, canManage, today } = props;
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

    const male = members.filter((member) => member.gender === 'L').length;
    const female = members.filter((member) => member.gender === 'P').length;

    return (
        <>
            <Head title={`Kelas ${item.name}`} />

            <div className="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-4 p-4">
                <div>
                    <Link
                        href="/kelas"
                        className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                    >
                        <ArrowLeft className="size-4" />
                        Daftar kelas
                    </Link>
                </div>

                <div className="space-y-2">
                    <Heading
                        title={`Kelas ${item.name}`}
                        description={`${item.organization} · Tahun ajaran ${year.name} · Tingkat ${item.grade}`}
                    />
                    <div className="-mt-6 flex flex-wrap gap-1.5">
                        {!item.is_active && (
                            <Badge variant="outline">Nonaktif</Badge>
                        )}
                        {year.current && <Badge>Tahun berjalan</Badge>}
                        <Badge variant="secondary">
                            {members.length} siswa (L {male} · P {female})
                        </Badge>
                    </div>
                    <p className="text-sm">
                        <span className="text-muted-foreground">
                            Wali kelas:{' '}
                        </span>
                        {item.homeroom ? item.homeroom.name : 'Belum ada'}
                        {item.homeroom && !item.homeroom.active && (
                            <span className="ml-1 text-amber-700 dark:text-amber-300">
                                (tidak aktif lagi di lembaga ini)
                            </span>
                        )}
                    </p>
                </div>

                {year.past && (
                    <Notice text="Tahun ajaran ini sudah lewat. Kelasnya hanya bisa dilihat." />
                )}
                {!year.past && !item.is_active && (
                    <Notice text="Kelas ini nonaktif, jadi tidak bisa diisi siswa. Aktifkan kembali dari daftar kelas bila perlu." />
                )}
                {year.starts_in_future && !year.past && (
                    <Notice
                        text={`Tahun ajaran ini belum mulai. Siswa yang dimasukkan tercatat masuk kelas mulai ${formatDate(year.start_date)}.`}
                    />
                )}

                {props.canYearEnd && members.length > 0 && (
                    <div className="rounded-xl border p-4 text-sm">
                        <p className="mb-3 text-muted-foreground">
                            Akhir tahun ajaran: naikkan, tinggalkan, atau
                            luluskan siswa kelas ini sekaligus.
                        </p>
                        <Button
                            variant="outline"
                            asChild
                            className="w-full sm:w-auto"
                        >
                            <Link href={`/kelas/${item.id}/akhir-tahun`}>
                                <CalendarCheck />
                                Proses akhir tahun
                            </Link>
                        </Button>
                    </div>
                )}

                {canManage && item.is_active && (
                    <section className="space-y-3">
                        <Button
                            type="button"
                            variant={
                                action?.kind === 'add' ? 'default' : 'outline'
                            }
                            onClick={() => toggle({ kind: 'add' })}
                            className="w-full sm:w-auto"
                        >
                            <UserPlus />
                            Masukkan siswa
                        </Button>
                        {action?.kind === 'add' && (
                            <AddMembersForm {...props} onDone={close} />
                        )}
                    </section>
                )}

                <section className="space-y-3 rounded-xl border p-4">
                    <h2 className="text-base font-medium">Anggota kelas</h2>
                    {members.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Belum ada siswa di kelas ini.
                        </p>
                    ) : (
                        <ul className="divide-y">
                            {members.map((member) => (
                                <li key={member.id} className="space-y-2 py-3">
                                    <MemberLine member={member} />
                                    {canManage && (
                                        <div className="grid grid-cols-[repeat(auto-fit,minmax(9rem,1fr))] gap-2 sm:flex sm:flex-wrap">
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant={
                                                    isOpen('move', member.id)
                                                        ? 'default'
                                                        : 'outline'
                                                }
                                                onClick={() =>
                                                    toggle({
                                                        kind: 'move',
                                                        id: member.id,
                                                    })
                                                }
                                            >
                                                <ArrowRightLeft />
                                                Pindah kelas
                                            </Button>
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant={
                                                    isOpen('remove', member.id)
                                                        ? 'destructive'
                                                        : 'outline'
                                                }
                                                onClick={() =>
                                                    toggle({
                                                        kind: 'remove',
                                                        id: member.id,
                                                    })
                                                }
                                            >
                                                <LogOut />
                                                Keluarkan
                                            </Button>
                                        </div>
                                    )}
                                    {canManage && isOpen('move', member.id) && (
                                        <MoveMemberForm
                                            member={{
                                                id: member.id,
                                                name: member.name,
                                                className: item.name,
                                                startDate: member.start_date,
                                            }}
                                            targets={props.moveTargets}
                                            today={today}
                                            futureYear={year.starts_in_future}
                                            onDone={close}
                                        />
                                    )}
                                    {canManage &&
                                        isOpen('remove', member.id) && (
                                            <RemoveMemberForm
                                                member={{
                                                    id: member.id,
                                                    name: member.name,
                                                    className: item.name,
                                                    startDate:
                                                        member.start_date,
                                                }}
                                                today={today}
                                                futureYear={
                                                    year.starts_in_future
                                                }
                                                onDone={close}
                                            />
                                        )}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                {former.length > 0 && (
                    <section className="space-y-3 rounded-xl border p-4">
                        <h2 className="text-base font-medium">
                            Pernah di kelas ini
                        </h2>
                        <ul className="divide-y">
                            {former.map((member) => (
                                <li key={member.id} className="py-3">
                                    <MemberLine member={member} />
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </>
    );
}

function MemberLine({ member }: { member: Member }) {
    return (
        <div className="flex flex-wrap items-start justify-between gap-2">
            <div className="min-w-0 space-y-0.5">
                <Link
                    href={`/siswa/${member.student_id}`}
                    className="font-medium break-words hover:underline"
                >
                    {member.name}
                </Link>
                <p className="text-sm text-muted-foreground">
                    {[
                        member.gender,
                        member.nisn ? `NISN ${member.nisn}` : null,
                        member.student_number
                            ? `No. induk ${member.student_number}`
                            : null,
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                </p>
            </div>
            <p className="text-sm text-muted-foreground">
                {formatDate(member.start_date)}
                {member.end_date ? ` – ${formatDate(member.end_date)}` : ''}
            </p>
        </div>
    );
}

/** Masukkan banyak siswa sekaligus: centang, cari nama, pilih semua yang tampil. */
function AddMembersForm({
    class: item,
    year,
    candidates,
    today,
    maxPerSave,
    onDone,
}: Props & { onDone: () => void }) {
    const [query, setQuery] = useState('');
    const [selected, setSelected] = useState<string[]>([]);

    const shown = useMemo(() => {
        const needle = query.trim().toLowerCase();

        return needle === ''
            ? candidates
            : candidates.filter(
                  (candidate) =>
                      candidate.name.toLowerCase().includes(needle) ||
                      (candidate.nisn ?? '').includes(needle) ||
                      (candidate.student_number ?? '')
                          .toLowerCase()
                          .includes(needle),
              );
    }, [candidates, query]);

    const toggleOne = (id: string) =>
        setSelected((current) =>
            current.includes(id)
                ? current.filter((value) => value !== id)
                : [...current, id],
        );
    const allShownSelected =
        shown.length > 0 &&
        shown.every((candidate) => selected.includes(candidate.enrollment_id));
    const toggleShown = () =>
        setSelected((current) =>
            allShownSelected
                ? current.filter(
                      (id) =>
                          !shown.some(
                              (candidate) => candidate.enrollment_id === id,
                          ),
                  )
                : Array.from(
                      new Set([
                          ...current,
                          ...shown.map((candidate) => candidate.enrollment_id),
                      ]),
                  ),
        );

    return (
        <Form
            action={`/kelas/${item.id}/anggota`}
            method="post"
            errorBag="addMembers"
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
                            Masukkan siswa ke {item.name}
                        </h3>
                        <p className="text-sm text-muted-foreground">
                            Pilihan: siswa aktif di lembaga ini yang belum punya
                            kelas di tahun ajaran {year.name}. Paling banyak{' '}
                            {maxPerSave} siswa sekali simpan.
                        </p>
                    </div>

                    {candidates.length === 0 ? (
                        <p className="text-sm">
                            Semua siswa aktif di lembaga ini sudah punya kelas
                            di tahun ajaran ini.
                        </p>
                    ) : (
                        <>
                            <div className="grid grid-cols-[repeat(auto-fit,minmax(12rem,1fr))] items-end gap-3">
                                <div className="grid gap-1.5">
                                    <Label htmlFor="am-search">Cari</Label>
                                    <Input
                                        id="am-search"
                                        type="search"
                                        value={query}
                                        placeholder="Nama, NISN, atau nomor induk"
                                        onChange={(event) =>
                                            setQuery(event.target.value)
                                        }
                                    />
                                </div>
                                {!year.starts_in_future && (
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="am-date">
                                            Tanggal masuk kelas
                                        </Label>
                                        <Input
                                            id="am-date"
                                            name="start_date"
                                            type="date"
                                            min={year.start_date}
                                            max={today}
                                            defaultValue={today}
                                            required
                                        />
                                        <InputError
                                            message={errors.start_date}
                                        />
                                    </div>
                                )}
                            </div>

                            <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
                                <label className="flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        className="size-4 accent-primary"
                                        checked={allShownSelected}
                                        onChange={toggleShown}
                                    />
                                    Pilih semua yang tampil ({shown.length})
                                </label>
                                <span className="font-medium">
                                    {selected.length} dipilih
                                </span>
                            </div>

                            <ul className="max-h-96 divide-y overflow-y-auto rounded-md border bg-background">
                                {shown.map((candidate) => (
                                    <li key={candidate.enrollment_id}>
                                        <label className="flex cursor-pointer items-start gap-3 p-3 text-sm">
                                            <input
                                                type="checkbox"
                                                checked={selected.includes(
                                                    candidate.enrollment_id,
                                                )}
                                                onChange={() =>
                                                    toggleOne(
                                                        candidate.enrollment_id,
                                                    )
                                                }
                                                className="mt-0.5 size-4 accent-primary"
                                            />
                                            <span className="min-w-0">
                                                <span className="block font-medium break-words">
                                                    {candidate.name}
                                                </span>
                                                <span className="text-muted-foreground">
                                                    {[
                                                        candidate.gender,
                                                        candidate.nisn
                                                            ? `NISN ${candidate.nisn}`
                                                            : null,
                                                        candidate.student_number
                                                            ? `No. induk ${candidate.student_number}`
                                                            : null,
                                                    ]
                                                        .filter(Boolean)
                                                        .join(' · ')}
                                                </span>
                                            </span>
                                        </label>
                                    </li>
                                ))}
                                {shown.length === 0 && (
                                    <li className="p-3 text-sm text-muted-foreground">
                                        Tidak ada yang cocok dengan pencarian.
                                    </li>
                                )}
                            </ul>
                        </>
                    )}

                    {/* Yang dikirim = semua yang dipilih, termasuk yang tersembunyi oleh pencarian. */}
                    {selected.map((id) => (
                        <input
                            key={id}
                            type="hidden"
                            name="enrollment_ids[]"
                            value={id}
                        />
                    ))}

                    <InputError
                        message={
                            errors.enrollment_ids ??
                            errors['enrollment_ids.0'] ??
                            errors.form
                        }
                    />
                    {selected.length > maxPerSave && (
                        <p className="text-sm text-red-600 dark:text-red-400">
                            Terlalu banyak: paling banyak {maxPerSave} siswa
                            sekali simpan.
                        </p>
                    )}

                    <div className="grid grid-cols-[repeat(auto-fit,minmax(9rem,1fr))] gap-2 sm:flex">
                        <Button
                            type="submit"
                            disabled={
                                processing ||
                                selected.length === 0 ||
                                selected.length > maxPerSave
                            }
                        >
                            {processing
                                ? 'Menyimpan…'
                                : `Masukkan ${selected.length} siswa`}
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

function Notice({ text }: { text: string }) {
    return (
        <p className="flex gap-2 rounded-md border border-amber-500/50 bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
            <CircleAlert className="mt-0.5 size-4 shrink-0" />
            {text}
        </p>
    );
}
