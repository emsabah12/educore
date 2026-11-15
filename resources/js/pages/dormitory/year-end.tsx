import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, CalendarCheck } from 'lucide-react';
import { ConfirmAction } from '@/components/confirm-action';
import { Empty } from '@/components/dormitory/facility-forms';
import { dateText } from '@/components/dormitory/types';
import Heading from '@/components/heading';
import { selectClass } from '@/components/native-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

type YearOption = { name: string; end_date: string; processed: boolean };

type PreviewRoom = {
    id: string;
    code: string;
    building: string;
    policy: 'TETAP' | 'KOSONGKAN';
    policy_label: string;
    emptied: number;
    kept: number;
    review: number;
};

type Totals = { emptied: number; kept: number; review: number };

type Props = {
    dormitory: { id: string; code: string; name: string };
    years: YearOption[];
    chosen: string | null;
    preview: { year: YearOption; rooms: PreviewRoom[]; totals: Totals } | null;
    previewError: string | null;
    history: { year: string }[];
};

/**
 * Proses akhir tahun asrama (PRD-008 §9, §19.2): 1) pilih tahun ajaran yang sudah berakhir, 2) pratinjau per kamar,
 * 3) jalankan — semua atau tidak. Kamar Kosongkan: santri Aktif yang masuk paling lambat akhir tahun ajaran diakhiri
 * (alasan Akhir tahun). Kamar Tetap tidak diubah.
 */
export default function YearEnd({
    dormitory,
    years,
    chosen,
    preview,
    previewError,
}: Props) {
    const base = `/asrama/${dormitory.id}`;
    const choose = (name: string) =>
        router.get(`${base}/akhir-tahun`, name ? { tahun: name } : {}, {
            preserveScroll: true,
        });

    return (
        <>
            <Head title={`Proses akhir tahun · ${dormitory.name}`} />

            <div className="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-4 p-4">
                <Link
                    href={`${base}?tab=pengaturan`}
                    className="flex w-fit items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" />
                    {dormitory.name}
                </Link>

                <Heading
                    title="Proses akhir tahun"
                    description="Kosongkan kamar berkebijakan Kosongkan setelah tahun ajaran berakhir. Pengawas, rencana penempatan, dan santri yang masuk sesudah akhir tahun ajaran tidak diubah."
                />

                {years.length === 0 ? (
                    <Empty>
                        Belum ada tahun ajaran yang sudah berakhir di kalender
                        lembaga milik asrama ini.
                    </Empty>
                ) : (
                    <div className="grid max-w-sm gap-1.5">
                        <Label htmlFor="ye-year">1. Tahun ajaran</Label>
                        <select
                            id="ye-year"
                            className={selectClass}
                            value={chosen ?? ''}
                            onChange={(event) => choose(event.target.value)}
                        >
                            <option value="">Pilih tahun ajaran</option>
                            {years.map((year) => (
                                <option key={year.name} value={year.name}>
                                    {year.name} (berakhir{' '}
                                    {dateText(year.end_date)})
                                    {year.processed ? ' · sudah diproses' : ''}
                                </option>
                            ))}
                        </select>
                    </div>
                )}

                {previewError && (
                    <p className="text-sm text-destructive">{previewError}</p>
                )}

                {preview && (
                    <Preview
                        dormitoryUrl={base}
                        year={preview.year}
                        rooms={preview.rooms}
                        totals={preview.totals}
                    />
                )}
            </div>
        </>
    );
}

function Preview({
    dormitoryUrl,
    year,
    rooms,
    totals,
}: {
    dormitoryUrl: string;
    year: YearOption;
    rooms: PreviewRoom[];
    totals: Totals;
}) {
    return (
        <section className="space-y-4">
            <h2 className="text-sm font-semibold">
                2. Pratinjau · tahun ajaran {year.name}, berakhir{' '}
                {dateText(year.end_date)}
            </h2>

            <dl className="grid grid-cols-3 gap-3">
                {[
                    ['Diakhiri', totals.emptied],
                    ['Tetap', totals.kept],
                    ['Perlu ditinjau', totals.review],
                ].map(([label, value]) => (
                    <div key={label} className="rounded-xl border p-3">
                        <dt className="text-xs text-muted-foreground">
                            {label}
                        </dt>
                        <dd className="text-xl font-semibold tabular-nums">
                            {value}
                        </dd>
                    </div>
                ))}
            </dl>

            {rooms.length === 0 ? (
                <Empty>Asrama ini belum punya kamar.</Empty>
            ) : (
                <ul className="divide-y rounded-xl border">
                    {rooms.map((room) => (
                        <li
                            key={room.id}
                            className="flex flex-wrap items-center justify-between gap-2 px-4 py-2.5"
                        >
                            <div>
                                <Link
                                    href={`/asrama/kamar/${room.id}`}
                                    className="font-medium hover:underline"
                                >
                                    {room.code}
                                </Link>
                                <span className="text-xs text-muted-foreground">
                                    {' '}
                                    · {room.building}
                                </span>
                            </div>
                            <div className="flex flex-wrap items-center gap-2 text-sm">
                                <Badge
                                    variant={
                                        room.policy === 'KOSONGKAN'
                                            ? 'secondary'
                                            : 'outline'
                                    }
                                >
                                    {room.policy_label}
                                </Badge>
                                <span
                                    className={cn(
                                        'tabular-nums',
                                        room.policy === 'KOSONGKAN' &&
                                            room.emptied > 0 &&
                                            'font-medium',
                                    )}
                                >
                                    {room.policy === 'KOSONGKAN'
                                        ? `${room.emptied} diakhiri`
                                        : `${room.kept} tetap`}
                                </span>
                                {room.review > 0 && (
                                    <span className="text-xs text-amber-700 dark:text-amber-400">
                                        {room.review} perlu ditinjau
                                    </span>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            )}

            <p className="text-xs text-muted-foreground">
                Kebijakan kamar diubah lewat Ubah kamar. Santri yang perlu
                ditinjau tetap di kamarnya; check-out dari halaman kamar bila
                sudah keluar.
            </p>

            {year.processed ? (
                <p className="text-sm text-muted-foreground">
                    Asrama ini sudah diproses untuk tahun ajaran {year.name}.
                </p>
            ) : (
                <ConfirmAction
                    trigger={
                        <Button type="button">
                            <CalendarCheck />
                            3. Jalankan proses akhir tahun
                        </Button>
                    }
                    title={`Jalankan proses akhir tahun ${year.name}?`}
                    description={`${totals.emptied} santri di kamar Kosongkan diakhiri per ${dateText(year.end_date)} (alasan Akhir tahun) dan bed/lokernya lepas. ${totals.kept} santri di kamar Tetap tidak berubah. Proses ini hanya bisa dijalankan sekali per tahun ajaran.`}
                    action={`${dormitoryUrl}/akhir-tahun`}
                    confirmLabel="Ya, jalankan"
                    destructive={false}
                    hidden={{ year: year.name }}
                    errorBag="yearEnd"
                />
            )}
        </section>
    );
}
