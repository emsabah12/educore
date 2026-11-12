import { Head, Link, router } from '@inertiajs/react';
import { Check, Download, X } from 'lucide-react';
import Heading from '@/components/heading';
import { ConfirmAction } from '@/components/confirm-action';
import { formatTrainingDate } from '@/components/hr/employee-trainings';
import { selectClass } from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

type PendingRow = {
    id: string;
    employee_id: string;
    employee_name: string;
    organization: string | null;
    kind_label: string;
    title: string;
    organizer: string | null;
    start_date: string;
    end_date: string;
    hours: number | null;
    file_name: string | null;
    submitted_on: string;
};

type RecapRow = {
    employee_id: string;
    employee_name: string;
    employee_number: string | null;
    organization: string | null;
    count: number;
    hours: number;
};

type Props = {
    pending: PendingRow[];
    recap: RecapRow[];
    year: number;
    years: number[];
};

/**
 * Pelatihan pegawai (PRD-001 §19.1, HR-8a): pengajuan pegawai yang menunggu verifikasi dan rekap jam pelatihan per
 * tahun. Hanya Admin SDM / Admin Yayasan.
 */
export default function TrainingIndex({ pending, recap, year, years }: Props) {
    const totalHours = recap.reduce((sum, row) => sum + row.hours, 0);

    return (
        <>
            <Head title="Pelatihan pegawai" />

            <div className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-4 p-4">
                <Heading
                    title="Pelatihan pegawai"
                    description="Verifikasi pengajuan pelatihan dari pegawai dan rekap jam pelatihan per tahun. Catat pelatihan seorang pegawai lewat detail pegawai → tab Pelatihan."
                />

                <section className="space-y-3 rounded-xl border p-4">
                    <h2 className="text-base font-medium">
                        Menunggu verifikasi ({pending.length})
                    </h2>
                    {pending.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Tidak ada pengajuan yang menunggu.
                        </p>
                    ) : (
                        <ul className="divide-y rounded-lg border">
                            {pending.map((row) => (
                                <PendingItem key={row.id} row={row} />
                            ))}
                        </ul>
                    )}
                </section>

                <section className="space-y-3 rounded-xl border p-4">
                    <div className="flex flex-wrap items-end justify-between gap-3">
                        <div className="grid min-w-40 gap-1.5">
                            <Label htmlFor="training-year">Rekap tahun</Label>
                            <select
                                id="training-year"
                                className={selectClass}
                                value={year}
                                onChange={(event) =>
                                    router.get(
                                        '/pegawai/pelatihan',
                                        { tahun: event.target.value },
                                        { preserveScroll: true, replace: true },
                                    )
                                }
                            >
                                {years.map((option) => (
                                    <option key={option} value={option}>
                                        {option}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <Button variant="outline" asChild>
                            <a href={`/pegawai/pelatihan/unduh?tahun=${year}`}>
                                <Download />
                                Unduh .xlsx
                            </a>
                        </Button>
                    </div>

                    {recap.length === 0 ? (
                        <p className="rounded-lg border border-dashed p-4 text-center text-sm text-muted-foreground">
                            Belum ada pelatihan yang diterima pada tahun {year}.
                        </p>
                    ) : (
                        <>
                            <ul className="divide-y rounded-lg border">
                                {recap.map((row) => (
                                    <li key={row.employee_id}>
                                        <Link
                                            href={`/pegawai/${row.employee_id}?tab=pelatihan`}
                                            className="flex flex-wrap items-center justify-between gap-2 p-3 text-sm hover:bg-muted/40"
                                        >
                                            <div className="min-w-0">
                                                <p className="font-medium break-words">
                                                    {row.employee_name}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    {row.organization ??
                                                        'Tanpa penempatan utama'}
                                                </p>
                                            </div>
                                            <p className="text-sm whitespace-nowrap">
                                                {row.count} kegiatan ·{' '}
                                                <span className="font-semibold">
                                                    {row.hours} jam
                                                </span>
                                            </p>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                            <p className="text-right text-sm text-muted-foreground">
                                Jumlah {recap.length} pegawai · {totalHours} jam
                            </p>
                        </>
                    )}
                </section>
            </div>
        </>
    );
}

function PendingItem({ row }: { row: PendingRow }) {
    const base = `/pegawai/${row.employee_id}/pelatihan/${row.id}`;

    return (
        <li className="space-y-2 p-3 text-sm">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0 space-y-0.5">
                    <p className="font-medium break-words">{row.title}</p>
                    <p className="text-xs text-muted-foreground">
                        <Link
                            href={`/pegawai/${row.employee_id}?tab=pelatihan`}
                            className="underline-offset-2 hover:underline"
                        >
                            {row.employee_name}
                        </Link>
                        {row.organization && ` · ${row.organization}`} ·{' '}
                        {row.kind_label} ·{' '}
                        {row.start_date === row.end_date
                            ? formatTrainingDate(row.start_date)
                            : `${formatTrainingDate(row.start_date)} – ${formatTrainingDate(row.end_date)}`}
                        {row.hours !== null && ` · ${row.hours} jam`}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        {row.organizer && `${row.organizer} · `}diajukan{' '}
                        {formatTrainingDate(row.submitted_on)}
                    </p>
                </div>
                {row.file_name && (
                    <Button variant="outline" size="sm" asChild>
                        <a href={`${base}/unduh`}>
                            <Download />
                            Sertifikat
                        </a>
                    </Button>
                )}
            </div>
            <div className="flex flex-wrap gap-2">
                <ConfirmAction
                    trigger={
                        <Button type="button" size="sm">
                            <Check />
                            Terima
                        </Button>
                    }
                    title="Terima pengajuan pelatihan?"
                    description={`${row.title} (${row.employee_name}) masuk riwayat resmi dan rekap jam.`}
                    action={`${base}/terima`}
                    confirmLabel="Terima"
                    destructive={false}
                    errorBag={`pending-verify-${row.id}`}
                />
                <ConfirmAction
                    trigger={
                        <Button type="button" size="sm" variant="outline">
                            <X />
                            Tolak
                        </Button>
                    }
                    title="Tolak pengajuan pelatihan?"
                    description="Alasan penolakan terlihat oleh pegawai."
                    action={`${base}/tolak`}
                    confirmLabel="Tolak pengajuan"
                    reason={{
                        label: 'Alasan penolakan',
                        max: 300,
                        placeholder: 'mis. Sertifikat belum dilampirkan',
                    }}
                    errorBag={`pending-reject-${row.id}`}
                />
            </div>
        </li>
    );
}
