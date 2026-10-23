import { Form, Head } from '@inertiajs/react';
import { LogIn, LogOut } from 'lucide-react';
import Heading from '@/components/heading';
import { formatLeaveDate } from '@/components/hr/leave-format';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

type Day = {
    date: string;
    required: boolean;
    expected: string;
    status: string | null;
    status_label: string;
    check_in: string | null;
    check_out: string | null;
    check_in_self: boolean;
    check_out_self: boolean;
    late: number;
    early: number;
    note: string | null;
};

type Props =
    | { linked: false }
    | {
          linked: true;
          organization: string | null;
          today: Day | null;
          days: Day[];
          canCheckIn: boolean;
          canCheckOut: boolean;
      };

const STATUS_CLASS: Record<string, string> = {
    HADIR: 'border-emerald-500/50 bg-emerald-50 text-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100',
    DINAS_LUAR:
        'border-sky-500/50 bg-sky-50 text-sky-900 dark:bg-sky-950/40 dark:text-sky-100',
    SAKIT: 'border-amber-500/50 bg-amber-50 text-amber-900 dark:bg-amber-950/40 dark:text-amber-100',
    IZIN: 'border-amber-500/50 bg-amber-50 text-amber-900 dark:bg-amber-950/40 dark:text-amber-100',
    CUTI: 'border-violet-500/50 bg-violet-50 text-violet-900 dark:bg-violet-950/40 dark:text-violet-100',
    ALPA: 'border-red-500/50 bg-red-50 text-red-900 dark:bg-red-950/40 dark:text-red-100',
    BELUM: 'border-dashed text-muted-foreground',
};

/**
 * Presensi saya (PRD-001 §14.2, HR-4a): jam wajib hari ini, absen masuk/pulang (jam server), dan
 * riwayat bulan berjalan. Semua aturan dicek server.
 */
export default function MyAttendance(props: Props) {
    return (
        <>
            <Head title="Presensi saya" />

            <div className="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-4 p-4">
                <Heading
                    title="Presensi saya"
                    description="Absen masuk saat tiba dan absen pulang saat selesai bekerja. Jam yang dicatat adalah jam server, bukan jam HP."
                />

                {!props.linked ? (
                    <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                        Akun ini belum tertaut ke data pegawai. Hubungi Admin
                        SDM bila Anda pegawai yayasan.
                    </p>
                ) : props.today === null ? (
                    <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                        Anda belum punya penempatan utama, jadi belum bisa
                        absen. Hubungi Admin SDM.
                    </p>
                ) : (
                    <>
                        <TodayCard
                            today={props.today}
                            organization={props.organization}
                            canCheckIn={props.canCheckIn}
                            canCheckOut={props.canCheckOut}
                        />
                        <History days={props.days} />
                    </>
                )}
            </div>
        </>
    );
}

function TodayCard({
    today,
    organization,
    canCheckIn,
    canCheckOut,
}: {
    today: Day;
    organization: string | null;
    canCheckIn: boolean;
    canCheckOut: boolean;
}) {
    return (
        <section
            aria-label="Hari ini"
            className="space-y-3 rounded-xl border p-4"
        >
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0 space-y-0.5">
                    <p className="text-sm text-muted-foreground">
                        {formatLeaveDate(today.date)}
                        {organization && ` · ${organization}`}
                    </p>
                    <p className="text-base font-medium">
                        {today.required
                            ? `Jam wajib ${today.expected}`
                            : `${today.expected} — tidak wajib hadir`}
                    </p>
                </div>
                <StatusBadge day={today} />
            </div>

            <dl className="grid grid-cols-2 gap-3 text-sm">
                <div>
                    <dt className="text-muted-foreground">Masuk</dt>
                    <dd className="text-lg font-semibold">
                        {today.check_in ?? '—'}
                    </dd>
                    {today.late > 0 && (
                        <dd className="text-red-600 dark:text-red-400">
                            Terlambat {today.late} menit
                        </dd>
                    )}
                </div>
                <div>
                    <dt className="text-muted-foreground">Pulang</dt>
                    <dd className="text-lg font-semibold">
                        {today.check_out ?? '—'}
                    </dd>
                    {today.early > 0 && (
                        <dd className="text-amber-700 dark:text-amber-300">
                            {today.early} menit lebih awal
                        </dd>
                    )}
                </div>
            </dl>

            <div className="grid grid-cols-[repeat(auto-fit,minmax(10rem,1fr))] gap-2">
                <ActionForm
                    action="/presensi-saya/masuk"
                    label="Absen masuk"
                    icon={<LogIn />}
                    disabled={!canCheckIn}
                />
                <ActionForm
                    action="/presensi-saya/pulang"
                    label="Absen pulang"
                    icon={<LogOut />}
                    disabled={!canCheckOut}
                    variant="outline"
                />
            </div>
        </section>
    );
}

function ActionForm({
    action,
    label,
    icon,
    disabled,
    variant = 'default',
}: {
    action: string;
    label: string;
    icon: React.ReactNode;
    disabled: boolean;
    variant?: 'default' | 'outline';
}) {
    return (
        <Form
            action={action}
            method="post"
            options={{ preserveScroll: true }}
            className="grid gap-1"
        >
            {({ processing, errors }) => (
                <>
                    <Button
                        type="submit"
                        variant={variant}
                        disabled={disabled || processing}
                        className="h-11 w-full"
                    >
                        {icon}
                        {processing ? 'Menyimpan…' : label}
                    </Button>
                    <InputError message={errors.form} />
                </>
            )}
        </Form>
    );
}

function StatusBadge({ day }: { day: Day }) {
    if (day.status === null) {
        return null;
    }

    return (
        <Badge variant="outline" className={STATUS_CLASS[day.status] ?? ''}>
            {day.status_label}
        </Badge>
    );
}

function History({ days }: { days: Day[] }) {
    return (
        <section aria-label="Riwayat bulan ini" className="space-y-2">
            <h2 className="text-base font-medium">Bulan ini</h2>
            {days.length === 0 ? (
                <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                    Belum ada data.
                </p>
            ) : (
                <ul className="divide-y rounded-xl border">
                    {days.map((day) => (
                        <li
                            key={day.date}
                            className="flex flex-wrap items-start justify-between gap-2 p-3 text-sm"
                        >
                            <div className="min-w-0 space-y-0.5">
                                <p className="font-medium">
                                    {formatLeaveDate(day.date)}
                                </p>
                                <p className="text-muted-foreground">
                                    {day.expected}
                                </p>
                                {(day.check_in || day.check_out) && (
                                    <p>
                                        Masuk {day.check_in ?? '—'}
                                        {day.check_in &&
                                            !day.check_in_self &&
                                            ' (petugas)'}{' '}
                                        · Pulang {day.check_out ?? '—'}
                                        {day.check_out &&
                                            !day.check_out_self &&
                                            ' (petugas)'}
                                    </p>
                                )}
                                {(day.late > 0 || day.early > 0) && (
                                    <p className="text-red-600 dark:text-red-400">
                                        {[
                                            day.late > 0 &&
                                                `Terlambat ${day.late} menit`,
                                            day.early > 0 &&
                                                `Pulang ${day.early} menit lebih awal`,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </p>
                                )}
                                {day.note && (
                                    <p className="break-words text-muted-foreground">
                                        {day.note}
                                    </p>
                                )}
                            </div>
                            <StatusBadge day={day} />
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
