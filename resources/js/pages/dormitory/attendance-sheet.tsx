import { Form, Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, CircleAlert, RotateCcw } from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import type { AttendanceKindRow } from '@/components/dormitory/attendance-kinds';
import type { Option } from '@/components/dormitory/types';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { selectClass } from '@/components/native-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type SheetRow = {
    placement_id: string;
    membership_id: string;
    name: string;
    category: 'PENGHUNI' | 'PENGAWAS';
    category_label: string;
    bed: string | null;
    status: string;
    note: string | null;
};

type Props = {
    room: {
        id: string;
        code: string;
        name: string | null;
        building: string;
        gender_label: string;
    };
    dormitory: { id: string; name: string };
    asSupervisor: boolean;
    kinds: AttendanceKindRow[];
    kind: string | null;
    date: string;
    window: { min: string; max: string };
    statuses: Option[];
    sheet: {
        rows: SheetRow[];
        session: {
            recorded_by: string | null;
            updated_at: string | null;
        } | null;
    };
};

const PRESENT = 'H';
const NOTE_MAX = 200;
const SUPERVISOR_DAYS = 7;

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

function formatDate(value: string): string {
    const [year, month, day] = value.split('-').map(Number);

    return dateFormat.format(new Date(year, month - 1, day));
}

/** Warna tombol status yang terpilih; kode tetap tertulis agar tidak bergantung pada warna. */
const activeClass: Record<string, string> = {
    H: 'border-emerald-600 bg-emerald-600 text-white',
    I: 'border-amber-500 bg-amber-500 text-white',
    S: 'border-sky-600 bg-sky-600 text-white',
    T: 'border-red-600 bg-red-600 text-white',
};

/**
 * Lembar absen asrama satu kamar (PRD-008 §22.2): pilih jenis absen & tanggal, semua penghuni awalnya Hadir,
 * tandai yang Izin/Sakit/Tidak ada lalu simpan semua sekaligus. Aturan tanggal & daftar penghuni dicek server.
 */
export default function DormitoryAttendanceSheet(props: Props) {
    const { room, dormitory, asSupervisor, kinds, kind, date, window } = props;
    const baseUrl = `/asrama/kamar/${room.id}/absen`;
    const backUrl = asSupervisor
        ? '/kamar-saya'
        : `/asrama/absen?asrama=${dormitory.id}${kind ? `&jenis=${kind}` : ''}&tanggal=${date}`;

    const go = (params: { jenis?: string | null; tanggal?: string }) =>
        router.get(
            baseUrl,
            {
                jenis: params.jenis ?? kind ?? undefined,
                tanggal: params.tanggal ?? date,
            },
            { preserveScroll: true },
        );

    return (
        <>
            <Head title={`Absen kamar ${room.code}`} />

            <div className="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-4 p-4">
                <div>
                    <Link
                        href={backUrl}
                        className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                    >
                        <ArrowLeft className="size-4" />
                        {asSupervisor ? 'Kamar saya' : 'Absen asrama'}
                    </Link>
                </div>

                <Heading
                    title={`Absen kamar ${room.code}${room.name ? ` · ${room.name}` : ''}`}
                    description={`${dormitory.name} · ${room.building} · ${room.gender_label}`}
                />

                {kinds.length === 0 || kind === null ? (
                    <Notice>
                        Asrama ini belum punya jenis absen aktif. Pengurus
                        menambahkannya di detail asrama → tab Pengaturan.
                    </Notice>
                ) : (
                    <>
                        <div className="grid grid-cols-[repeat(auto-fit,minmax(12rem,1fr))] items-end gap-3 rounded-xl border p-4">
                            <div className="grid gap-1.5">
                                <Label htmlFor="att-kind">Jenis absen</Label>
                                <select
                                    id="att-kind"
                                    className={selectClass}
                                    value={kind}
                                    onChange={(event) =>
                                        go({ jenis: event.target.value })
                                    }
                                >
                                    {kinds.map((row) => (
                                        <option key={row.id} value={row.id}>
                                            {row.name}
                                        </option>
                                    ))}
                                </select>
                            </div>
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
                                            go({ tanggal: event.target.value });
                                        }
                                    }}
                                />
                            </div>
                            <p className="text-sm text-muted-foreground">
                                {asSupervisor
                                    ? `Wali kamar bisa mengisi atau mengoreksi ${SUPERVISOR_DAYS} hari terakhir.`
                                    : 'Pengurus bisa mengisi sampai 1 tahun ke belakang.'}
                            </p>
                        </div>

                        <SheetForm
                            key={`${kind}-${date}`}
                            {...props}
                            kind={kind}
                            baseUrl={baseUrl}
                        />
                    </>
                )}
            </div>
        </>
    );
}

function SheetForm({
    date,
    kind,
    kinds,
    sheet,
    statuses,
    baseUrl,
}: Props & { kind: string; baseUrl: string }) {
    const rows = sheet.rows;
    const session = sheet.session;
    const kindName = kinds.find((row) => row.id === kind)?.name ?? '';
    const [values, setValues] = useState<
        Record<string, { status: string; note: string }>
    >(() =>
        Object.fromEntries(
            rows.map((row) => [
                row.placement_id,
                { status: row.status, note: row.note ?? '' },
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

    if (rows.length === 0) {
        return (
            <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                Tidak ada penghuni di kamar ini pada {formatDate(date)}.
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
                    <input type="hidden" name="kind" value={kind} />

                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div className="space-y-0.5">
                            <h2 className="text-base font-medium">
                                {kindName} · {formatDate(date)}
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                {session
                                    ? `Sudah diisi${session.recorded_by ? ` oleh ${session.recorded_by}` : ''}${session.updated_at ? `, ${stampFormat.format(new Date(session.updated_at))}` : ''}. Simpan lagi untuk mengoreksi.`
                                    : 'Belum diisi. Semua penghuni awalnya Hadir.'}
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
                        {rows.map((row, index) => {
                            const value = values[row.placement_id];

                            return (
                                <li
                                    key={row.placement_id}
                                    className="grid gap-2 px-4 py-3 sm:grid-cols-[1fr_auto] sm:items-center"
                                >
                                    <div className="min-w-0">
                                        <p className="font-medium break-words">
                                            <span className="mr-1 text-muted-foreground tabular-nums">
                                                {index + 1}.
                                            </span>
                                            {row.name}
                                            {row.category === 'PENGAWAS' && (
                                                <Badge
                                                    variant="secondary"
                                                    className="ml-2"
                                                >
                                                    Pengawas
                                                </Badge>
                                            )}
                                        </p>
                                        {row.bed && (
                                            <p className="text-sm text-muted-foreground">
                                                Bed {row.bed}
                                            </p>
                                        )}
                                    </div>
                                    <fieldset className="grid grid-cols-4 gap-1">
                                        <legend className="sr-only">
                                            Status kehadiran {row.name}
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
                                                            : 'bg-card hover:bg-muted')
                                                    }
                                                >
                                                    <input
                                                        type="radio"
                                                        className="sr-only"
                                                        name={`entries[${row.placement_id}][status]`}
                                                        value={status.value}
                                                        checked={checked}
                                                        onChange={() =>
                                                            set(
                                                                row.placement_id,
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
                                                htmlFor={`att-note-${row.placement_id}`}
                                                className="sr-only"
                                            >
                                                Keterangan {row.name}
                                            </Label>
                                            <Input
                                                id={`att-note-${row.placement_id}`}
                                                name={`entries[${row.placement_id}][note]`}
                                                value={value.note}
                                                maxLength={NOTE_MAX}
                                                placeholder="Keterangan (opsional)"
                                                onChange={(event) =>
                                                    set(row.placement_id, {
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
                        H = Hadir · I = Izin · S = Sakit · T = Tidak ada
                    </p>

                    <InputError
                        message={errors.form ?? errors.date ?? errors.kind}
                    />

                    <div className="grid grid-cols-[repeat(auto-fit,minmax(10rem,1fr))] gap-2 sm:flex">
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Menyimpan…' : 'Simpan absen'}
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

function Notice({ children }: { children: ReactNode }) {
    return (
        <p className="flex gap-2 rounded-md border border-amber-500/50 bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
            <CircleAlert className="mt-0.5 size-4 shrink-0" />
            <span>{children}</span>
        </p>
    );
}
