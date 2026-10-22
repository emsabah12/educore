import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft, Ban } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import {
    formatLeaveDateTime,
    formatLeaveRange,
    LEAVE_STATUS_CLASS,
} from '@/components/hr/leave-format';
import type { LeaveStatus } from '@/components/hr/leave-format';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

type Decision = {
    decision: string;
    label: string;
    by: string | null;
    note: string | null;
    decided_at: string;
};

type Props = {
    leave: {
        id: string;
        employee_id: string;
        employee: string;
        type: string;
        organization: string;
        start_date: string;
        end_date: string;
        days: number;
        days_by_year: Record<string, number>;
        reason: string;
        status: LeaveStatus;
        status_label: string;
        recorded_by: string | null;
        created_at: string | null;
        decisions: Decision[];
    };
    canCancel: boolean;
    own: boolean;
    reasonMax: number;
};

/**
 * Detail pengajuan cuti untuk Admin SDM (PRD-001 §13.5, HR-3d): data pengajuan, riwayat keputusan,
 * dan pembatalan cuti yang sudah disetujui.
 */
export default function LeaveDetail({
    leave,
    canCancel,
    own,
    reasonMax,
}: Props) {
    const years = Object.entries(leave.days_by_year);

    return (
        <>
            <Head title={`Cuti ${leave.employee}`} />

            <div className="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-4 p-4">
                <Button
                    variant="ghost"
                    size="sm"
                    asChild
                    className="self-start"
                >
                    <Link href="/pegawai/cuti">
                        <ArrowLeft />
                        Kelola cuti
                    </Link>
                </Button>

                <div className="flex flex-wrap items-start justify-between gap-2">
                    <Heading
                        title={leave.employee}
                        description={leave.organization}
                    />
                    <Badge
                        variant="outline"
                        className={LEAVE_STATUS_CLASS[leave.status]}
                    >
                        {leave.status_label}
                    </Badge>
                </div>

                <dl className="grid gap-3 rounded-xl border p-4 text-sm sm:grid-cols-2">
                    <Item label="Jenis">{leave.type}</Item>
                    <Item label="Tanggal">
                        {formatLeaveRange(leave.start_date, leave.end_date)}
                    </Item>
                    <Item label="Jumlah">
                        {leave.days} hari kerja
                        {years.length > 1 &&
                            ` (${years.map(([year, days]) => `${year}: ${days}`).join(', ')})`}
                    </Item>
                    <Item label="Dicatat">
                        {leave.recorded_by
                            ? `Oleh ${leave.recorded_by} (atas nama pegawai)`
                            : 'Diajukan pegawai sendiri'}
                        {leave.created_at &&
                            `, ${formatLeaveDateTime(leave.created_at)}`}
                    </Item>
                    <div className="sm:col-span-2">
                        <Item label="Alasan">
                            <span className="break-words whitespace-pre-line">
                                {leave.reason}
                            </span>
                        </Item>
                    </div>
                    <div className="sm:col-span-2">
                        <Link
                            href={`/pegawai/${leave.employee_id}`}
                            className="text-sm underline underline-offset-4"
                        >
                            Lihat data pegawai
                        </Link>
                    </div>
                </dl>

                <section aria-label="Riwayat keputusan" className="space-y-2">
                    <h2 className="text-base font-medium">Riwayat keputusan</h2>
                    {leave.decisions.length === 0 ? (
                        <p className="rounded-xl border border-dashed p-4 text-sm text-muted-foreground">
                            Belum ada keputusan.
                        </p>
                    ) : (
                        <ol className="divide-y rounded-xl border">
                            {leave.decisions.map((decision, index) => (
                                <li
                                    key={index}
                                    className="space-y-0.5 p-4 text-sm"
                                >
                                    <p className="font-medium">
                                        {decision.label}
                                    </p>
                                    <p className="text-muted-foreground">
                                        {decision.by ? `${decision.by} · ` : ''}
                                        {formatLeaveDateTime(
                                            decision.decided_at,
                                        )}
                                    </p>
                                    {decision.note && (
                                        <p className="break-words whitespace-pre-line">
                                            {decision.note}
                                        </p>
                                    )}
                                </li>
                            ))}
                        </ol>
                    )}
                    {leave.status === 'MENUNGGU' && (
                        <p className="text-sm text-muted-foreground">
                            Keputusan tahap berjalan diambil di{' '}
                            <Link
                                href="/persetujuan-cuti"
                                className="underline underline-offset-4"
                            >
                                Persetujuan cuti
                            </Link>
                            .
                        </p>
                    )}
                </section>

                {canCancel && (
                    <CancelForm leaveId={leave.id} reasonMax={reasonMax} />
                )}
                {own && leave.status === 'DISETUJUI' && (
                    <p className="text-sm text-muted-foreground">
                        Ini cuti Anda sendiri; pembatalan dilakukan Admin SDM
                        lain.
                    </p>
                )}
            </div>
        </>
    );
}

function Item({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="space-y-0.5">
            <dt className="text-muted-foreground">{label}</dt>
            <dd>{children}</dd>
        </div>
    );
}

function CancelForm({
    leaveId,
    reasonMax,
}: {
    leaveId: string;
    reasonMax: number;
}) {
    const [open, setOpen] = useState(false);

    if (!open) {
        return (
            <Button
                type="button"
                variant="outline"
                className="w-full self-start sm:w-auto"
                onClick={() => setOpen(true)}
            >
                <Ban />
                Batalkan cuti
            </Button>
        );
    }

    return (
        <Form
            action={`/pegawai/cuti/${leaveId}/batalkan`}
            method="post"
            options={{ preserveScroll: true }}
            className="space-y-2 rounded-xl border border-red-500/40 p-4"
        >
            {({ processing, errors }) => (
                <>
                    <p className="text-sm">
                        Cuti yang dibatalkan tidak lagi memakai jatah. Alasan
                        pembatalan bisa dibaca pegawai di Cuti saya.
                    </p>
                    <label
                        htmlFor="cancel-reason"
                        className="text-sm font-medium"
                    >
                        Alasan pembatalan
                    </label>
                    <textarea
                        id="cancel-reason"
                        name="reason"
                        rows={2}
                        maxLength={reasonMax}
                        required
                        className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                    />
                    <InputError message={errors.reason ?? errors.form} />
                    <div className="grid grid-cols-[repeat(auto-fit,minmax(9rem,1fr))] gap-2 sm:flex">
                        <Button
                            type="submit"
                            variant="destructive"
                            disabled={processing}
                        >
                            Batalkan cuti
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setOpen(false)}
                        >
                            Tidak jadi
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}
