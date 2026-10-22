import { Form, Head } from '@inertiajs/react';
import { CircleAlert, Plane, Undo2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Balance = {
    type_id: string;
    name: string;
    quota: number;
    adjustment: number;
    used: number;
    pending: number;
    remaining: number;
};

type LeaveTypeOption = {
    id: string;
    name: string;
    has_quota: boolean;
    max_days: number | null;
};

type LeaveRow = {
    id: string;
    type: string;
    start_date: string;
    end_date: string;
    days: number;
    status: 'MENUNGGU' | 'DISETUJUI' | 'DITOLAK' | 'DITARIK' | 'DIBATALKAN';
    status_label: string;
    step: number | null;
    steps: number;
    steps_label: string;
    reason: string;
    created_at: string | null;
};

type Props =
    | { linked: false }
    | {
          linked: true;
          year: number;
          balances: Balance[];
          types: LeaveTypeOption[];
          requests: LeaveRow[];
          today: string;
          limits: { reason: number; backdate: number };
      };

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:opacity-50 dark:bg-input/30';

const dateFormatter = new Intl.DateTimeFormat('id-ID', {
    weekday: 'short',
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

const STATUS_CLASS: Record<LeaveRow['status'], string> = {
    MENUNGGU:
        'border-amber-500/50 bg-amber-50 text-amber-900 dark:bg-amber-950/40 dark:text-amber-100',
    DISETUJUI:
        'border-emerald-500/50 bg-emerald-50 text-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100',
    DITOLAK:
        'border-red-500/50 bg-red-50 text-red-900 dark:bg-red-950/40 dark:text-red-100',
    DITARIK: 'text-muted-foreground',
    DIBATALKAN: 'text-muted-foreground',
};

/**
 * Cuti saya (PRD-001 §13.3, HR-3b): saldo jatah tahun berjalan, ajukan cuti (jumlah hari kerja
 * dihitung server), dan riwayat pengajuan. Semua aturan dicek server.
 */
export default function MyLeave(props: Props) {
    return (
        <>
            <Head title="Cuti saya" />

            <div className="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-6 p-4">
                <Heading
                    title="Cuti saya"
                    description="Ajukan cuti atau izin, lihat sisa jatah, dan pantau persetujuannya."
                />

                {!props.linked ? (
                    <p className="flex gap-2 rounded-md border border-amber-500/50 bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
                        <CircleAlert className="mt-0.5 size-4 shrink-0" />
                        Akun Anda belum tertaut ke data pegawai di yayasan ini.
                        Hubungi Admin SDM.
                    </p>
                ) : (
                    <>
                        <Balances year={props.year} balances={props.balances} />
                        <RequestForm {...props} />
                        <History requests={props.requests} />
                    </>
                )}
            </div>
        </>
    );
}

function Balances({ year, balances }: { year: number; balances: Balance[] }) {
    if (balances.length === 0) {
        return null;
    }

    return (
        <section aria-label="Sisa jatah" className="space-y-2">
            <h2 className="text-base font-medium">Sisa jatah tahun {year}</h2>
            <div className="grid grid-cols-[repeat(auto-fit,minmax(13rem,1fr))] gap-3">
                {balances.map((balance) => (
                    <div
                        key={balance.type_id}
                        className="space-y-1 rounded-xl border p-4 text-sm"
                    >
                        <p className="font-medium">{balance.name}</p>
                        <p className="text-2xl font-semibold tabular-nums">
                            {balance.remaining}
                            <span className="text-sm font-normal text-muted-foreground">
                                {' '}
                                dari {balance.quota + balance.adjustment} hari
                            </span>
                        </p>
                        <p className="text-muted-foreground">
                            Terpakai {balance.used}
                            {balance.pending > 0 &&
                                ` · menunggu persetujuan ${balance.pending}`}
                        </p>
                    </div>
                ))}
            </div>
            <p className="text-xs text-muted-foreground">
                Sisa jatah tidak dibawa ke tahun berikutnya.
            </p>
        </section>
    );
}

type Preview =
    | { state: 'idle' }
    | { state: 'loading' }
    | { state: 'ok'; days: number; organization: string }
    | { state: 'error'; message: string };

function RequestForm(props: Extract<Props, { linked: true }>) {
    const [start, setStart] = useState('');
    const [end, setEnd] = useState('');
    const [typeId, setTypeId] = useState('');
    const [preview, setPreview] = useState<Preview>({ state: 'idle' });
    const type = props.types.find((option) => option.id === typeId);
    const balance = props.balances.find((item) => item.type_id === typeId);

    // Jumlah hari kerja dihitung server (libur mingguan lembaga & tanggal merah).
    useEffect(() => {
        if (start === '' || end === '' || end < start) {
            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            setPreview({ state: 'loading' });
            fetch(
                `/cuti-saya/hitung?mulai=${encodeURIComponent(start)}&selesai=${encodeURIComponent(end)}`,
                {
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                },
            )
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
    }, [start, end]);

    const shownPreview: Preview =
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
        shownPreview.state === 'ok' &&
        balance !== undefined &&
        shownPreview.days > balance.remaining - balance.pending;
    const maxDays = type?.max_days ?? null;
    const overMax =
        shownPreview.state === 'ok' &&
        maxDays !== null &&
        shownPreview.days > maxDays;

    return (
        <section aria-label="Ajukan cuti" className="space-y-2">
            <h2 className="text-base font-medium">Ajukan cuti</h2>
            <Form
                action="/cuti-saya"
                method="post"
                errorBag="leaveRequest"
                options={{ preserveScroll: true }}
                resetOnSuccess
                onSuccess={() => {
                    setStart('');
                    setEnd('');
                    setTypeId('');
                    setPreview({ state: 'idle' });
                }}
                className="space-y-4 rounded-xl border p-4"
            >
                {({ processing, errors }) => (
                    <>
                        <InputError message={errors.form} />
                        <div className="grid gap-3 sm:grid-cols-3">
                            <div className="grid content-start gap-1.5">
                                <Label htmlFor="leave-type">Jenis</Label>
                                <select
                                    id="leave-type"
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
                                    {props.types.map((option) => (
                                        <option
                                            key={option.id}
                                            value={option.id}
                                        >
                                            {option.name}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.leave_type_id} />
                            </div>
                            <div className="grid content-start gap-1.5">
                                <Label htmlFor="leave-start">
                                    Tanggal mulai
                                </Label>
                                <Input
                                    id="leave-start"
                                    type="date"
                                    name="start_date"
                                    value={start}
                                    onChange={(event) => {
                                        setStart(event.target.value);

                                        // Cuti satu hari: tanggal selesai ikut terisi.
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
                                <Label htmlFor="leave-end">
                                    Tanggal selesai
                                </Label>
                                <Input
                                    id="leave-end"
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

                        <PreviewLine
                            preview={shownPreview}
                            overQuota={overQuota}
                            overMax={overMax}
                            maxDays={maxDays}
                        />

                        <div className="grid gap-1.5">
                            <Label htmlFor="leave-reason">Alasan</Label>
                            <textarea
                                id="leave-reason"
                                name="reason"
                                rows={3}
                                maxLength={props.limits.reason}
                                required
                                className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                            />
                            <p className="text-xs text-muted-foreground">
                                Hanya dilihat penyetuju dan Admin SDM. Tanggal
                                mulai paling lama {props.limits.backdate} hari
                                sebelum hari ini.
                            </p>
                            <InputError message={errors.reason} />
                        </div>

                        <Button
                            type="submit"
                            disabled={processing}
                            className="w-full sm:w-auto"
                        >
                            <Plane />
                            {processing ? 'Mengirim…' : 'Ajukan cuti'}
                        </Button>
                    </>
                )}
            </Form>
        </section>
    );
}

function PreviewLine({
    preview,
    overQuota,
    overMax,
    maxDays,
}: {
    preview: Preview;
    overQuota: boolean;
    overMax: boolean;
    maxDays: number | null;
}) {
    if (preview.state === 'idle') {
        return null;
    }

    if (preview.state === 'loading') {
        return (
            <p className="text-sm text-muted-foreground">Menghitung hari…</p>
        );
    }

    if (preview.state === 'error') {
        return (
            <p className="text-sm text-red-600 dark:text-red-400">
                {preview.message}
            </p>
        );
    }

    return (
        <div className="space-y-1 rounded-md bg-muted/50 px-3 py-2 text-sm">
            <p>
                <strong>{preview.days} hari kerja</strong> (libur mingguan &
                tanggal merah {preview.organization} tidak dihitung).
            </p>
            {overMax && (
                <p className="text-red-600 dark:text-red-400">
                    Melebihi batas {maxDays} hari per pengajuan untuk jenis ini.
                </p>
            )}
            {overQuota && (
                <p className="text-red-600 dark:text-red-400">
                    Melebihi sisa jatah (sudah dikurangi pengajuan yang masih
                    menunggu).
                </p>
            )}
        </div>
    );
}

function History({ requests }: { requests: LeaveRow[] }) {
    const [confirm, setConfirm] = useState<string | null>(null);

    return (
        <section aria-label="Riwayat pengajuan" className="space-y-2">
            <h2 className="text-base font-medium">Riwayat pengajuan</h2>
            {requests.length === 0 ? (
                <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                    Belum ada pengajuan cuti.
                </p>
            ) : (
                <ul className="divide-y rounded-xl border">
                    {requests.map((row) => (
                        <li key={row.id} className="space-y-2 p-4 text-sm">
                            <div className="flex flex-wrap items-start justify-between gap-2">
                                <div className="min-w-0 space-y-0.5">
                                    <p className="font-medium">
                                        {row.type} · {row.days} hari
                                    </p>
                                    <p className="text-muted-foreground">
                                        {formatRange(
                                            row.start_date,
                                            row.end_date,
                                        )}
                                    </p>
                                    <p className="break-words whitespace-pre-line text-muted-foreground">
                                        {row.reason}
                                    </p>
                                </div>
                                <Badge
                                    variant="outline"
                                    className={STATUS_CLASS[row.status]}
                                >
                                    {row.status_label}
                                </Badge>
                            </div>
                            {row.status === 'MENUNGGU' &&
                                (confirm === row.id ? (
                                    <Form
                                        action={`/cuti-saya/${row.id}/tarik`}
                                        method="post"
                                        errorBag={`withdraw-${row.id}`}
                                        options={{ preserveScroll: true }}
                                        onSuccess={() => setConfirm(null)}
                                        className="space-y-2 rounded-md border p-3"
                                    >
                                        {({ processing, errors }) => (
                                            <>
                                                <p>
                                                    Tarik pengajuan ini?
                                                    Pengajuan tidak bisa
                                                    dikembalikan; ajukan lagi
                                                    bila perlu.
                                                </p>
                                                <InputError
                                                    message={errors.form}
                                                />
                                                <div className="grid grid-cols-[repeat(auto-fit,minmax(9rem,1fr))] gap-2 sm:flex">
                                                    <Button
                                                        type="submit"
                                                        variant="destructive"
                                                        disabled={processing}
                                                    >
                                                        Ya, tarik
                                                    </Button>
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        onClick={() =>
                                                            setConfirm(null)
                                                        }
                                                    >
                                                        Batal
                                                    </Button>
                                                </div>
                                            </>
                                        )}
                                    </Form>
                                ) : (
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={() => setConfirm(row.id)}
                                        className="w-full sm:w-auto"
                                    >
                                        <Undo2 />
                                        Tarik pengajuan
                                    </Button>
                                ))}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
