import { Form } from '@inertiajs/react';
import { CalendarOff, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/*
 * Hari libur kalender akademik (PRD-002 §18 P4): hari libur mingguan per kalender dan daftar hari
 * libur per tahun ajaran. Semua aturan tetap dicek server; form di sini hanya membantu.
 */

export type HolidayRow = {
    id: string;
    name: string;
    start_date: string;
    end_date: string;
    days: number;
};

type Option = { value: string; label: string };

const dateFormatter = new Intl.DateTimeFormat('id-ID', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    timeZone: 'UTC',
});

function formatDate(value: string): string {
    return dateFormatter.format(new Date(`${value}T00:00:00Z`));
}

function formatRange(start: string, end: string): string {
    return start === end
        ? formatDate(start)
        : `${formatDate(start)} – ${formatDate(end)}`;
}

/** Hari libur mingguan satu kalender: tampil ringkas, centang ulang saat diubah. */
export function WeeklyOffForm({
    calendarId,
    days,
    options,
}: {
    calendarId: string;
    days: number[];
    options: Option[];
}) {
    const [editing, setEditing] = useState(false);
    const [selected, setSelected] = useState<number[]>(days);
    const names = options
        .filter((option) => days.includes(Number(option.value)))
        .map((option) => option.label);

    const toggle = (day: number) =>
        setSelected((current) =>
            current.includes(day)
                ? current.filter((value) => value !== day)
                : [...current, day],
        );

    if (!editing) {
        return (
            <div className="flex flex-wrap items-center justify-between gap-2 rounded-xl border p-4 text-sm">
                <p className="flex flex-wrap items-center gap-2">
                    <CalendarOff className="size-4" />
                    <span className="text-muted-foreground">
                        Libur mingguan:
                    </span>
                    <span className="font-medium">
                        {names.length > 0 ? names.join(', ') : 'Tidak ada'}
                    </span>
                    <span className="w-full text-xs text-muted-foreground">
                        Untuk hari sekolah (presensi siswa, jadwal & presensi
                        mengajar). Hari kerja pegawai untuk cuti & absen staf
                        diatur terpisah di Pengaturan cuti & presensi.
                    </span>
                </p>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => {
                        setSelected(days);
                        setEditing(true);
                    }}
                >
                    <Pencil />
                    Ubah libur mingguan
                </Button>
            </div>
        );
    }

    return (
        <Form
            action={`/akademik/kalender/${calendarId}/libur-mingguan`}
            method="post"
            errorBag="weekly-off"
            options={{ preserveScroll: true }}
            onSuccess={() => setEditing(false)}
            className="space-y-3 rounded-xl border border-primary/30 bg-muted/20 p-4"
        >
            {({ processing, errors }) => (
                <>
                    <div className="space-y-1">
                        <h4 className="font-medium">Hari libur mingguan</h4>
                        <p className="text-sm text-muted-foreground">
                            Hari yang dicentang tidak dihitung sebagai hari
                            efektif di semua tahun ajaran kalender ini.
                        </p>
                    </div>
                    <div className="grid grid-cols-[repeat(auto-fit,minmax(7rem,1fr))] gap-2">
                        {options.map((option) => {
                            const day = Number(option.value);

                            return (
                                <label
                                    key={option.value}
                                    className="flex cursor-pointer items-center gap-2 rounded-md border bg-background p-2 text-sm hover:bg-muted"
                                >
                                    <input
                                        type="checkbox"
                                        name="weekly_off_days[]"
                                        value={option.value}
                                        checked={selected.includes(day)}
                                        onChange={() => toggle(day)}
                                        className="size-4 accent-primary"
                                    />
                                    {option.label}
                                </label>
                            );
                        })}
                    </div>
                    <InputError
                        message={
                            errors.weekly_off_days ??
                            errors['weekly_off_days.0'] ??
                            errors.form
                        }
                    />
                    <div className="grid grid-cols-[repeat(auto-fit,minmax(9rem,1fr))] gap-2 sm:flex">
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
    );
}

type OpenAction =
    | { kind: 'add' }
    | { kind: 'edit'; id: string }
    | { kind: 'remove'; id: string };

/** Daftar hari libur satu tahun ajaran beserta tambah/ubah/hapus. */
export function HolidayList({
    year,
}: {
    year: {
        id: string;
        name: string;
        start_date: string;
        end_date: string;
        holidays: HolidayRow[];
    };
}) {
    const [action, setAction] = useState<OpenAction | null>(null);
    const close = () => setAction(null);
    const isOpen = (kind: 'edit' | 'remove', id: string) =>
        action !== null &&
        action.kind === kind &&
        'id' in action &&
        action.id === id;
    const toggle = (next: OpenAction) =>
        setAction((current) =>
            current &&
            current.kind === next.kind &&
            ('id' in current ? current.id : '') ===
                ('id' in next ? next.id : '')
                ? null
                : next,
        );

    return (
        <div className="space-y-2">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-sm font-medium">
                    Hari libur ({year.holidays.length})
                </p>
                <Button
                    type="button"
                    size="sm"
                    variant={action?.kind === 'add' ? 'default' : 'outline'}
                    onClick={() => toggle({ kind: 'add' })}
                >
                    <Plus />
                    Tambah hari libur
                </Button>
            </div>

            {action?.kind === 'add' && (
                <HolidayForm
                    title={`Tambah hari libur ${year.name}`}
                    action={`/akademik/tahun-ajaran/${year.id}/hari-libur`}
                    method="post"
                    errorBag={`holiday-add-${year.id}`}
                    min={year.start_date}
                    max={year.end_date}
                    onDone={close}
                />
            )}

            {year.holidays.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    Belum ada hari libur di tahun ajaran ini.
                </p>
            ) : (
                <ul className="divide-y rounded-md border">
                    {year.holidays.map((holiday) => (
                        <li key={holiday.id} className="space-y-2 p-3">
                            <div className="flex flex-wrap items-start justify-between gap-2">
                                <div className="min-w-0 text-sm">
                                    <p className="font-medium break-words">
                                        {holiday.name}
                                    </p>
                                    <p className="text-muted-foreground">
                                        {formatRange(
                                            holiday.start_date,
                                            holiday.end_date,
                                        )}{' '}
                                        · {holiday.days} hari
                                    </p>
                                </div>
                                <div className="grid w-full grid-cols-[repeat(auto-fit,minmax(7rem,1fr))] gap-2 sm:flex sm:w-auto">
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant={
                                            isOpen('edit', holiday.id)
                                                ? 'default'
                                                : 'outline'
                                        }
                                        onClick={() =>
                                            toggle({
                                                kind: 'edit',
                                                id: holiday.id,
                                            })
                                        }
                                    >
                                        <Pencil />
                                        Ubah
                                    </Button>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant={
                                            isOpen('remove', holiday.id)
                                                ? 'destructive'
                                                : 'outline'
                                        }
                                        onClick={() =>
                                            toggle({
                                                kind: 'remove',
                                                id: holiday.id,
                                            })
                                        }
                                    >
                                        <Trash2 />
                                        Hapus
                                    </Button>
                                </div>
                            </div>

                            {isOpen('edit', holiday.id) && (
                                <HolidayForm
                                    title={`Ubah hari libur ${holiday.name}`}
                                    action={`/akademik/hari-libur/${holiday.id}`}
                                    method="patch"
                                    errorBag={`holiday-${holiday.id}`}
                                    min={year.start_date}
                                    max={year.end_date}
                                    initial={holiday}
                                    onDone={close}
                                />
                            )}

                            {isOpen('remove', holiday.id) && (
                                <Form
                                    action={`/akademik/hari-libur/${holiday.id}`}
                                    method="delete"
                                    errorBag={`holiday-remove-${holiday.id}`}
                                    options={{ preserveScroll: true }}
                                    onSuccess={close}
                                    className="space-y-3 rounded-md border border-red-500/40 bg-red-50/50 p-3 text-sm dark:bg-red-950/20"
                                >
                                    {({ processing, errors }) => (
                                        <>
                                            <p>
                                                Hapus hari libur{' '}
                                                <strong>{holiday.name}</strong>?
                                                Tanggalnya kembali dihitung
                                                sebagai hari efektif.
                                            </p>
                                            <InputError message={errors.form} />
                                            <div className="grid grid-cols-[repeat(auto-fit,minmax(9rem,1fr))] gap-2 sm:flex">
                                                <Button
                                                    type="submit"
                                                    variant="destructive"
                                                    disabled={processing}
                                                >
                                                    {processing
                                                        ? 'Menghapus…'
                                                        : 'Hapus hari libur'}
                                                </Button>
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    onClick={close}
                                                >
                                                    Batal
                                                </Button>
                                            </div>
                                        </>
                                    )}
                                </Form>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function HolidayForm({
    title,
    action,
    method,
    errorBag,
    min,
    max,
    initial,
    onDone,
}: {
    title: string;
    action: string;
    method: 'post' | 'patch';
    errorBag: string;
    min: string;
    max: string;
    initial?: HolidayRow;
    onDone: () => void;
}) {
    const [start, setStart] = useState(initial?.start_date ?? '');
    const [end, setEnd] = useState(initial?.end_date ?? '');
    const prefix = `${errorBag}-`;

    return (
        <Form
            action={action}
            method={method}
            errorBag={errorBag}
            options={{ preserveScroll: true }}
            onSuccess={onDone}
            className="space-y-3 rounded-md border border-primary/30 bg-muted/20 p-3"
        >
            {({ processing, errors }) => (
                <>
                    <p className="text-sm font-medium">{title}</p>
                    <InputError message={errors.form} />
                    <div className="grid gap-3 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)]">
                        <div className="grid content-start gap-1.5">
                            <Label htmlFor={`${prefix}name`}>Nama</Label>
                            <Input
                                id={`${prefix}name`}
                                name="name"
                                defaultValue={initial?.name ?? ''}
                                maxLength={100}
                                placeholder="mis. Libur akhir semester"
                                required
                            />
                            <InputError message={errors.name} />
                        </div>
                        <div className="grid content-start gap-1.5">
                            <Label htmlFor={`${prefix}start`}>
                                Tanggal mulai
                            </Label>
                            <Input
                                id={`${prefix}start`}
                                type="date"
                                name="start_date"
                                value={start}
                                min={min}
                                max={max}
                                onChange={(event) => {
                                    setStart(event.target.value);

                                    // Libur satu hari: tanggal selesai ikut terisi.
                                    if (
                                        end === '' ||
                                        end < event.target.value
                                    ) {
                                        setEnd(event.target.value);
                                    }
                                }}
                                required
                            />
                            <InputError message={errors.start_date} />
                        </div>
                        <div className="grid content-start gap-1.5">
                            <Label htmlFor={`${prefix}end`}>
                                Tanggal selesai
                            </Label>
                            <Input
                                id={`${prefix}end`}
                                type="date"
                                name="end_date"
                                value={end}
                                min={start || min}
                                max={max}
                                onChange={(event) => setEnd(event.target.value)}
                                required
                            />
                            <InputError message={errors.end_date} />
                        </div>
                    </div>
                    <div className="grid grid-cols-[repeat(auto-fit,minmax(9rem,1fr))] gap-2 sm:flex">
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Menyimpan…' : 'Simpan'}
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
