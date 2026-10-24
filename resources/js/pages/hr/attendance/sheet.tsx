import { Form, Head, Link, router } from '@inertiajs/react';
import { ChartColumn } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Option = { value: string; label: string };

type StatusOption = Option & { times: boolean };

type SheetRecord = {
    status: string;
    status_label: string;
    check_in: string | null;
    check_out: string | null;
    check_in_source: string | null;
    check_out_source: string | null;
    late: number;
    early: number;
    note: string | null;
    updated_by: string | null;
};

type SheetRow = {
    employee_id: string;
    name: string;
    employee_number: string | null;
    expected: { required: boolean; label: string };
    /** Jenis cuti disetujui hari itu: baris tidak bisa diisi. */
    leave: string | null;
    record: SheetRecord | null;
};

type Props = {
    organizations: Option[];
    organization: Option;
    date: string;
    today: string;
    /** Tanggal paling awal yang boleh diisi; null = bebas (Admin SDM/Yayasan). */
    earliest: string | null;
    rows: SheetRow[];
    statuses: StatusOption[];
    noteMax: number;
    officerDays: number;
};

const BASE_URL = '/pegawai/presensi';

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';

const dateFormatter = new Intl.DateTimeFormat('id-ID', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
    timeZone: 'UTC',
});

/**
 * Lembar presensi pegawai per lembaga & tanggal (PRD-001 §14.3, HR-4b). Semua aturan dicek server.
 */
export default function AttendanceSheetPage(props: Props) {
    const go = (query: { lembaga?: string; tanggal?: string }) =>
        router.get(
            BASE_URL,
            {
                lembaga: query.lembaga ?? props.organization.value,
                tanggal: query.tanggal ?? props.date,
            },
            { preserveState: false, replace: true },
        );

    const filled = props.rows.filter((row) => row.record).length;
    const onLeave = props.rows.filter((row) => row.leave).length;

    return (
        <>
            <Head title="Presensi pegawai" />

            <div className="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-4 p-4">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <Heading
                        title="Presensi pegawai"
                        description="Isi atau koreksi kehadiran pegawai yang penempatan utamanya di lembaga ini. Absen mandiri pegawai tampil otomatis; cuti yang disetujui tidak perlu diisi."
                    />
                    <div className="grid grid-cols-[repeat(auto-fit,minmax(10rem,1fr))] gap-2 sm:flex">
                        <Button variant="outline" asChild>
                            <Link
                                href={`/pegawai/presensi/rekap?lembaga=${props.organization.value}`}
                            >
                                <ChartColumn />
                                Rekap bulanan
                            </Link>
                        </Button>
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
                            min={props.earliest ?? undefined}
                            max={props.today}
                            onChange={(event) =>
                                event.target.value &&
                                go({ tanggal: event.target.value })
                            }
                        />
                    </div>
                </div>

                <div className="space-y-1 text-sm">
                    <p className="font-medium">
                        {dateFormatter.format(
                            new Date(`${props.date}T00:00:00Z`),
                        )}
                    </p>
                    <p className="text-muted-foreground">
                        {props.rows.length} pegawai · {filled} sudah diisi
                        {onLeave > 0 && ` · ${onLeave} cuti`}
                        {props.earliest &&
                            ` · Petugas lembaga bisa mengisi ${props.officerDays} hari terakhir.`}
                    </p>
                </div>

                {props.rows.length === 0 ? (
                    <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                        Tidak ada pegawai dengan penempatan utama di lembaga ini
                        pada tanggal tersebut.
                    </p>
                ) : (
                    <Form
                        action={BASE_URL}
                        method="post"
                        options={{ preserveScroll: true }}
                        className="space-y-3"
                    >
                        {({ processing, errors }) => (
                            <>
                                <input
                                    type="hidden"
                                    name="organization_id"
                                    value={props.organization.value}
                                />
                                <input
                                    type="hidden"
                                    name="date"
                                    value={props.date}
                                />
                                <InputError
                                    message={errors.form ?? errors.date}
                                />
                                <ul className="space-y-3">
                                    {props.rows.map((row) => (
                                        <EmployeeRow
                                            key={`${row.employee_id}-${props.date}`}
                                            row={row}
                                            statuses={props.statuses}
                                            noteMax={props.noteMax}
                                            errors={errors}
                                        />
                                    ))}
                                </ul>
                                <div className="sticky bottom-0 grid grid-cols-[repeat(auto-fit,minmax(10rem,1fr))] gap-2 border-t bg-background py-3 sm:flex">
                                    <Button type="submit" disabled={processing}>
                                        {processing
                                            ? 'Menyimpan…'
                                            : 'Simpan presensi'}
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                )}
            </div>
        </>
    );
}

function EmployeeRow({
    row,
    statuses,
    noteMax,
    errors,
}: {
    row: SheetRow;
    statuses: StatusOption[];
    noteMax: number;
    errors: Record<string, string | undefined>;
}) {
    const [status, setStatus] = useState(row.record?.status ?? '');
    const hasTimes = statuses.some(
        (option) => option.value === status && option.times,
    );
    const name = (field: string) => `rows[${row.employee_id}][${field}]`;
    const id = (field: string) => `att-${row.employee_id}-${field}`;
    const error = (field: string) => errors[`rows.${row.employee_id}.${field}`];

    return (
        <li className="space-y-3 rounded-xl border p-3 text-sm">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0">
                    <p className="font-medium break-words">{row.name}</p>
                    <p className="text-muted-foreground">
                        {row.employee_number && `${row.employee_number} · `}
                        {row.expected.required
                            ? `Wajib ${row.expected.label}`
                            : row.expected.label}
                    </p>
                </div>
                {row.leave ? (
                    <Badge variant="outline">Cuti ({row.leave})</Badge>
                ) : row.record ? (
                    <div className="flex flex-wrap items-center gap-1.5">
                        {row.record.late > 0 && (
                            <Badge
                                variant="outline"
                                className="border-amber-500/50 text-amber-800 dark:text-amber-200"
                            >
                                Terlambat {row.record.late} menit
                            </Badge>
                        )}
                        {row.record.early > 0 && (
                            <Badge
                                variant="outline"
                                className="border-amber-500/50 text-amber-800 dark:text-amber-200"
                            >
                                Pulang cepat {row.record.early} menit
                            </Badge>
                        )}
                        {(row.record.check_in_source === 'MANDIRI' ||
                            row.record.check_out_source === 'MANDIRI') && (
                            <Badge variant="secondary">Absen mandiri</Badge>
                        )}
                    </div>
                ) : (
                    <Badge
                        variant="outline"
                        className="border-dashed text-muted-foreground"
                    >
                        Belum diisi
                    </Badge>
                )}
            </div>

            {!row.leave && (
                <div className="grid gap-3 sm:grid-cols-[repeat(auto-fit,minmax(9rem,1fr))]">
                    <div className="grid gap-1.5">
                        <Label htmlFor={id('status')}>Status</Label>
                        <select
                            id={id('status')}
                            name={name('status')}
                            className={selectClass}
                            value={status}
                            onChange={(event) => setStatus(event.target.value)}
                        >
                            <option value="">Belum diisi</option>
                            {statuses.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                        <InputError message={error('status')} />
                    </div>
                    {hasTimes && (
                        <>
                            <div className="grid gap-1.5">
                                <Label htmlFor={id('in')}>Jam masuk</Label>
                                <Input
                                    id={id('in')}
                                    name={name('check_in')}
                                    type="time"
                                    defaultValue={row.record?.check_in ?? ''}
                                />
                                <InputError message={error('check_in')} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor={id('out')}>Jam pulang</Label>
                                <Input
                                    id={id('out')}
                                    name={name('check_out')}
                                    type="time"
                                    defaultValue={row.record?.check_out ?? ''}
                                />
                                <InputError message={error('check_out')} />
                            </div>
                        </>
                    )}
                    <div className="grid gap-1.5 sm:col-span-full">
                        <Label htmlFor={id('note')}>
                            Keterangan (opsional)
                        </Label>
                        <Input
                            id={id('note')}
                            name={name('note')}
                            maxLength={noteMax}
                            defaultValue={row.record?.note ?? ''}
                        />
                        <InputError message={error('note')} />
                    </div>
                </div>
            )}

            {row.record?.updated_by && (
                <p className="text-xs text-muted-foreground">
                    Terakhir diubah {row.record.updated_by}
                </p>
            )}
        </li>
    );
}
