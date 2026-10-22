import { Form, Head } from '@inertiajs/react';
import { Check, X } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

type InboxRow = {
    id: string;
    employee: string;
    type: string;
    organization: string;
    start_date: string;
    end_date: string;
    days: number;
    reason: string;
    step: number;
    steps: number;
    quota: { remaining: number; pending_other: number } | null;
    created_at: string | null;
    lembaga_skipped: boolean;
};

type Props = {
    requests: InboxRow[];
    noteMax: number;
};

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

/**
 * Persetujuan cuti (PRD-001 §13.4, HR-3c): pengajuan yang menunggu keputusan pengguna ini — tahap
 * lembaga (Kepala Lembaga) atau tahap SDM (Admin SDM). Semua hak & aturan dicek server.
 */
export default function LeaveApprovals({ requests, noteMax }: Props) {
    return (
        <>
            <Head title="Persetujuan cuti" />

            <div className="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-4 p-4">
                <Heading
                    title="Persetujuan cuti"
                    description="Pengajuan yang menunggu keputusan Anda, terlama di atas. Cuti Anda sendiri tidak tampil di sini."
                />

                {requests.length === 0 ? (
                    <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                        Tidak ada pengajuan yang menunggu keputusan Anda.
                    </p>
                ) : (
                    <ul className="space-y-3">
                        {requests.map((row) => (
                            <Item key={row.id} row={row} noteMax={noteMax} />
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}

function Item({ row, noteMax }: { row: InboxRow; noteMax: number }) {
    const [mode, setMode] = useState<'setuju' | 'tolak' | null>(null);
    const available =
        row.quota === null
            ? null
            : row.quota.remaining - row.quota.pending_other;

    return (
        <li className="space-y-3 rounded-xl border p-4 text-sm">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0 space-y-0.5">
                    <p className="font-medium break-words">{row.employee}</p>
                    <p className="text-muted-foreground">{row.organization}</p>
                </div>
                <Badge variant="outline">
                    {row.step === 1
                        ? `Tahap lembaga${row.steps === 2 ? ' (1 dari 2)' : ''}`
                        : 'Tahap SDM'}
                </Badge>
            </div>

            <div className="space-y-1">
                <p>
                    <span className="font-medium">{row.type}</span> · {row.days}{' '}
                    hari kerja
                </p>
                <p className="text-muted-foreground">
                    {row.start_date === row.end_date
                        ? formatDate(row.start_date)
                        : `${formatDate(row.start_date)} – ${formatDate(row.end_date)}`}
                </p>
                <p className="break-words whitespace-pre-line">
                    <span className="text-muted-foreground">Alasan: </span>
                    {row.reason}
                </p>
                {row.quota && (
                    <p
                        className={
                            available !== null && available < row.days
                                ? 'text-red-600 dark:text-red-400'
                                : 'text-muted-foreground'
                        }
                    >
                        Sisa jatah tahun ini {row.quota.remaining} hari
                        {row.quota.pending_other > 0 &&
                            ` (pengajuan lain menunggu ${row.quota.pending_other} hari)`}
                    </p>
                )}
                {row.lembaga_skipped && (
                    <p className="text-muted-foreground">
                        Tahap lembaga dilewati: pengaju adalah penyetuju
                        lembaganya sendiri.
                    </p>
                )}
            </div>

            {mode === null ? (
                <div className="grid grid-cols-[repeat(auto-fit,minmax(9rem,1fr))] gap-2 sm:flex">
                    <Button type="button" onClick={() => setMode('setuju')}>
                        <Check />
                        Setujui
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => setMode('tolak')}
                    >
                        <X />
                        Tolak
                    </Button>
                </div>
            ) : (
                <Form
                    action={`/persetujuan-cuti/${row.id}`}
                    method="post"
                    errorBag={`decide-${row.id}`}
                    options={{ preserveScroll: true }}
                    className="space-y-2 rounded-md border border-primary/30 bg-muted/20 p-3"
                >
                    {({ processing, errors }) => (
                        <>
                            <input type="hidden" name="decision" value={mode} />
                            <label
                                htmlFor={`note-${row.id}`}
                                className="text-sm font-medium"
                            >
                                {mode === 'tolak'
                                    ? 'Alasan penolakan (dibaca pengaju)'
                                    : 'Catatan (opsional)'}
                            </label>
                            <textarea
                                id={`note-${row.id}`}
                                name="note"
                                rows={2}
                                maxLength={noteMax}
                                required={mode === 'tolak'}
                                className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                            />
                            <InputError message={errors.note ?? errors.form} />
                            <div className="grid grid-cols-[repeat(auto-fit,minmax(9rem,1fr))] gap-2 sm:flex">
                                <Button
                                    type="submit"
                                    variant={
                                        mode === 'tolak'
                                            ? 'destructive'
                                            : 'default'
                                    }
                                    disabled={processing}
                                >
                                    {mode === 'tolak'
                                        ? 'Tolak cuti'
                                        : 'Setujui cuti'}
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setMode(null)}
                                >
                                    Batal
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            )}
        </li>
    );
}
