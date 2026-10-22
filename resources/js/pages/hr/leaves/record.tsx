import { Form, Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Search, UserCheck, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Candidate = {
    id: string;
    name: string;
    employee_number: string | null;
    organization: string;
};

type Balance = {
    type_id: string;
    name: string;
    quota: number;
    adjustment: number;
    used: number;
    pending: number;
    remaining: number;
};

type TypeOption = {
    value: string;
    label: string;
    has_quota: boolean;
    max_days: number | null;
};

type Props = {
    search: string;
    candidates: Candidate[];
    employee: Candidate | null;
    balances: Balance[];
    types: TypeOption[];
    year: number;
    today: string;
    limits: { reason: number; backdate: number; minSearch: number };
};

type Preview =
    | { state: 'idle' }
    | { state: 'loading' }
    | { state: 'ok'; days: number; organization: string }
    | { state: 'error'; message: string };

const BASE_URL = '/pegawai/cuti';

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';

/**
 * Catat cuti atas nama pegawai (PRD-001 §13.5, HR-3d): cari & pilih pegawai, lalu isi jenis,
 * tanggal, alasan. Langsung disetujui; aturan hari, jatah & tumpang tindih dicek server.
 */
export default function RecordLeave(props: Props) {
    return (
        <>
            <Head title="Catat cuti" />

            <div className="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-4 p-4">
                <Button
                    variant="ghost"
                    size="sm"
                    asChild
                    className="self-start"
                >
                    <Link href={BASE_URL}>
                        <ArrowLeft />
                        Kelola cuti
                    </Link>
                </Button>

                <Heading
                    title="Catat cuti atas nama pegawai"
                    description="Untuk pegawai yang belum punya akun atau melapor langsung. Cuti yang dicatat di sini langsung disetujui dan memakai jatah."
                />

                {props.employee === null ? (
                    <EmployeeSearch {...props} />
                ) : (
                    <RecordForm {...props} employee={props.employee} />
                )}
            </div>
        </>
    );
}

function EmployeeSearch({ search, candidates, limits }: Props) {
    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        // FormData bisa berisi File; yang dipakai hanya teks.
        const raw = new FormData(event.currentTarget).get('cari');
        const value = typeof raw === 'string' ? raw.trim() : '';

        router.get(`${BASE_URL}/catat`, value === '' ? {} : { cari: value }, {
            preserveState: true,
            replace: true,
        });
    };

    const searched = search.length >= limits.minSearch;

    return (
        <section aria-label="Pilih pegawai" className="space-y-3">
            <form
                onSubmit={submit}
                className="grid grid-cols-[minmax(0,1fr)_auto] items-end gap-2 rounded-xl border p-4"
            >
                <div className="grid gap-1.5">
                    <Label htmlFor="cari">Cari pegawai</Label>
                    <Input
                        id="cari"
                        name="cari"
                        defaultValue={search}
                        placeholder="Nama atau nomor induk"
                        minLength={limits.minSearch}
                        autoFocus
                    />
                </div>
                <Button type="submit">
                    <Search />
                    Cari
                </Button>
            </form>

            {!searched ? (
                <p className="text-sm text-muted-foreground">
                    Ketik minimal {limits.minSearch} huruf. Hanya pegawai aktif
                    yang punya penempatan utama yang bisa dicatat cutinya.
                </p>
            ) : candidates.length === 0 ? (
                <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                    Tidak ada pegawai aktif yang cocok dengan “{search}”.
                </p>
            ) : (
                <ul className="divide-y rounded-xl border">
                    {candidates.map((candidate) => (
                        <li key={candidate.id}>
                            <Link
                                href={`${BASE_URL}/catat?pegawai=${candidate.id}`}
                                className="flex flex-wrap items-center justify-between gap-2 p-4 text-sm hover:bg-muted/50"
                            >
                                <span className="min-w-0">
                                    <span className="block font-medium break-words">
                                        {candidate.name}
                                    </span>
                                    <span className="block text-muted-foreground">
                                        {[
                                            candidate.employee_number,
                                            candidate.organization,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </span>
                                </span>
                                <UserCheck className="size-4 text-muted-foreground" />
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

function RecordForm({
    employee,
    balances,
    types,
    year,
    limits,
}: Props & { employee: Candidate }) {
    const [start, setStart] = useState('');
    const [end, setEnd] = useState('');
    const [typeId, setTypeId] = useState('');
    const [preview, setPreview] = useState<Preview>({ state: 'idle' });
    const type = types.find((option) => option.value === typeId);
    const balance = balances.find((item) => item.type_id === typeId);

    // Jumlah hari kerja dihitung server (libur mingguan lembaga & tanggal merah).
    useEffect(() => {
        if (start === '' || end === '' || end < start) {
            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            setPreview({ state: 'loading' });
            const query = new URLSearchParams({
                pegawai: employee.id,
                mulai: start,
                selesai: end,
            });

            fetch(`${BASE_URL}/hitung?${query.toString()}`, {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            })
                .then(async (response) => {
                    const body = (await response.json()) as {
                        days?: number;
                        organization?: string;
                        message?: string;
                    };

                    setPreview(
                        response.ok && body.days !== undefined
                            ? {
                                  state: 'ok',
                                  days: body.days,
                                  organization: body.organization ?? '',
                              }
                            : {
                                  state: 'error',
                                  message:
                                      body.message ??
                                      'Jumlah hari tidak bisa dihitung.',
                              },
                    );
                })
                .catch((error: unknown) => {
                    if (!(error instanceof DOMException)) {
                        setPreview({
                            state: 'error',
                            message:
                                'Jumlah hari belum bisa dihitung. Periksa koneksi lalu coba lagi.',
                        });
                    }
                });
        }, 300);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [start, end, employee.id]);

    const shown: Preview =
        start === '' || end === ''
            ? { state: 'idle' }
            : end < start
              ? {
                    state: 'error',
                    message:
                        'Tanggal selesai tidak boleh sebelum tanggal mulai.',
                }
              : preview;

    const overQuota =
        shown.state === 'ok' &&
        balance !== undefined &&
        shown.days > balance.remaining - balance.pending;
    const maxDays = type?.max_days ?? null;
    const overMax =
        shown.state === 'ok' && maxDays !== null && shown.days > maxDays;

    return (
        <section aria-label="Data cuti" className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-2 rounded-xl border p-4 text-sm">
                <div className="min-w-0">
                    <p className="font-medium break-words">{employee.name}</p>
                    <p className="text-muted-foreground">
                        {[employee.employee_number, employee.organization]
                            .filter(Boolean)
                            .join(' · ')}
                    </p>
                </div>
                <Button variant="outline" size="sm" asChild>
                    <Link href={`${BASE_URL}/catat`}>
                        <X />
                        Ganti pegawai
                    </Link>
                </Button>
            </div>

            {balances.length > 0 && (
                <div className="grid grid-cols-[repeat(auto-fit,minmax(12rem,1fr))] gap-2">
                    {balances.map((item) => (
                        <div
                            key={item.type_id}
                            className="rounded-xl border p-3 text-sm"
                        >
                            <p className="text-muted-foreground">
                                {item.name} {year}
                            </p>
                            <p className="text-lg font-semibold">
                                Sisa {item.remaining} dari{' '}
                                {item.quota + item.adjustment}
                            </p>
                            {item.pending > 0 && (
                                <p className="text-muted-foreground">
                                    Menunggu persetujuan {item.pending}
                                </p>
                            )}
                        </div>
                    ))}
                </div>
            )}

            <Form
                action={BASE_URL}
                method="post"
                options={{ preserveScroll: true }}
                className="space-y-4 rounded-xl border p-4"
            >
                {({ processing, errors }) => (
                    <>
                        <input
                            type="hidden"
                            name="employee_id"
                            value={employee.id}
                        />
                        <InputError
                            message={errors.form ?? errors.employee_id}
                        />
                        <div className="grid gap-3 sm:grid-cols-3">
                            <div className="grid content-start gap-1.5">
                                <Label htmlFor="record-type">Jenis</Label>
                                <select
                                    id="record-type"
                                    name="leave_type_id"
                                    className={selectClass}
                                    value={typeId}
                                    onChange={(event) =>
                                        setTypeId(event.target.value)
                                    }
                                    required
                                >
                                    <option value="" disabled>
                                        Pilih jenis cuti
                                    </option>
                                    {types.map((option) => (
                                        <option
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.leave_type_id} />
                            </div>
                            <div className="grid content-start gap-1.5">
                                <Label htmlFor="record-start">
                                    Tanggal mulai
                                </Label>
                                <Input
                                    id="record-start"
                                    type="date"
                                    name="start_date"
                                    value={start}
                                    onChange={(event) => {
                                        setStart(event.target.value);

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
                                <Label htmlFor="record-end">
                                    Tanggal selesai
                                </Label>
                                <Input
                                    id="record-end"
                                    type="date"
                                    name="end_date"
                                    value={end}
                                    min={start || undefined}
                                    onChange={(event) =>
                                        setEnd(event.target.value)
                                    }
                                    required
                                />
                                <InputError message={errors.end_date} />
                            </div>
                        </div>

                        {shown.state === 'loading' && (
                            <p className="text-sm text-muted-foreground">
                                Menghitung hari…
                            </p>
                        )}
                        {shown.state === 'error' && (
                            <p className="text-sm text-red-600 dark:text-red-400">
                                {shown.message}
                            </p>
                        )}
                        {shown.state === 'ok' && (
                            <div className="space-y-1 rounded-md bg-muted/50 px-3 py-2 text-sm">
                                <p>
                                    <strong>{shown.days} hari kerja</strong>{' '}
                                    (libur mingguan & tanggal merah{' '}
                                    {shown.organization} tidak dihitung).
                                </p>
                                {overMax && (
                                    <p className="text-red-600 dark:text-red-400">
                                        Melebihi batas {maxDays} hari per
                                        pengajuan untuk jenis ini.
                                    </p>
                                )}
                                {overQuota && (
                                    <p className="text-red-600 dark:text-red-400">
                                        Melebihi sisa jatah (sudah dikurangi
                                        pengajuan yang masih menunggu).
                                    </p>
                                )}
                            </div>
                        )}

                        <div className="grid gap-1.5">
                            <Label htmlFor="record-reason">
                                Alasan / keterangan
                            </Label>
                            <textarea
                                id="record-reason"
                                name="reason"
                                rows={3}
                                maxLength={limits.reason}
                                required
                                className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                            />
                            <p className="text-xs text-muted-foreground">
                                Tanggal mulai paling lama {limits.backdate} hari
                                sebelum hari ini.
                            </p>
                            <InputError message={errors.reason} />
                        </div>

                        <Button
                            type="submit"
                            disabled={processing}
                            className="w-full sm:w-auto"
                        >
                            {processing ? 'Menyimpan…' : 'Catat & setujui'}
                        </Button>
                    </>
                )}
            </Form>
        </section>
    );
}
