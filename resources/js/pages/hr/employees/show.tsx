import { Head, Link } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRightLeft,
    BadgeCheck,
    Lock,
    MapPinPlus,
    Pencil,
    RotateCcw,
    UserX,
} from 'lucide-react';
import { useState } from 'react';
import type { LoginAccess } from '@/components/core/login-access';
import { LoginAccessSection } from '@/components/core/login-access';
import type { ManageData } from '@/components/hr/employee-actions';
import {
    AddPlacementForm,
    EndEmploymentForm,
    EndPlacementForm,
    MakePrimaryButton,
    ProfileForm,
    RehireForm,
    StatusForm,
    TransferForm,
} from '@/components/hr/employee-actions';
import Heading from '@/components/heading';
import type { AttendanceSummaryData } from '@/components/hr/attendance-summary';
import { AttendanceSummary } from '@/components/hr/attendance-summary';
import {
    formatLeaveRange,
    LEAVE_STATUS_CLASS,
} from '@/components/hr/leave-format';
import type { LeaveStatus } from '@/components/hr/leave-format';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

type Placement = {
    id: string;
    organization: string;
    organization_code: string;
    position: string;
    is_primary: boolean;
    start_date: string;
    end_date: string | null;
};

type Period = {
    id: string;
    employment_type: string;
    classification: string | null;
    start_date: string;
    end_date: string | null;
    end_reason: string | null;
    end_note: string | null;
};

type Employee = {
    id: string;
    name: string;
    gender: string | null;
    status: 'AKTIF' | 'BERHENTI';
    status_label: string;
    has_account: boolean;
    employee_number: string | null;
    nuptk: string | null;
    education: string | null;
    education_major: string | null;
    service_length: string;
    personal: {
        /** NIK tersamar, mis. "3201 •••• •••• 0001". */
        nik: string | null;
        nik_warning: string | null;
        birth_place: string | null;
        birth_date: string | null;
        phone: string | null;
        address: string | null;
    } | null;
    periods: Period[];
    placements: { current: Placement[]; history: Placement[] };
};

type Props = {
    employee: Employee;
    canManage: boolean;
    manage: ManageData | null;
    /** Hanya untuk Admin Yayasan (PRD-000 §4.7). */
    access: LoginAccess | null;
    /** Ringkasan cuti (HR-3d-b), hanya bagi yang boleh melihat data pribadi. */
    leave: LeaveSummary | null;
    /** Ringkasan presensi bulan berjalan (HR-4c), hanya bagi yang boleh melihat data pribadi. */
    attendance: AttendanceSummaryData | null;
    canManageLeave: boolean;
};

type LeaveSummary = {
    year: number;
    balances: {
        type_id: string;
        name: string;
        quota: number;
        adjustment: number;
        used: number;
        pending: number;
        remaining: number;
    }[];
    recent: {
        id: string;
        type: string;
        start_date: string;
        end_date: string;
        days: number;
        status: LeaveStatus;
        status_label: string;
    }[];
};

type EmployeeAction = 'profile' | 'status' | 'addPlacement' | 'end' | 'rehire';

type PlacementAction = { id: string; kind: 'transfer' | 'end' };

const dateFormat = new Intl.DateTimeFormat('id-ID', { dateStyle: 'long' });

/** "2020-07-01" → "1 Juli 2020" tanpa geser zona waktu. */
function formatDate(value: string | null): string {
    if (!value) {
        return '–';
    }

    const [year, month, day] = value.slice(0, 10).split('-').map(Number);

    return dateFormat.format(new Date(year, month - 1, day));
}

/**
 * Detail pegawai (PRD-001 §6). Aksi (HR-1d) hanya tampil untuk Admin SDM / Admin Yayasan;
 * server tetap memeriksa ulang setiap aksi.
 */
export default function EmployeeDetail({
    employee,
    manage,
    access,
    leave,
    attendance,
    canManageLeave,
}: Props) {
    const lastPeriod = employee.periods[0];
    const isActive = employee.status === 'AKTIF';
    const [action, setAction] = useState<EmployeeAction | null>(null);
    const [placementAction, setPlacementAction] =
        useState<PlacementAction | null>(null);

    const toggle = (next: EmployeeAction) => {
        setPlacementAction(null);
        setAction((current) => (current === next ? null : next));
    };

    const close = () => {
        setAction(null);
        setPlacementAction(null);
    };

    return (
        <>
            <Head title={employee.name} />

            <div className="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-4 p-4">
                <div>
                    <Link
                        href="/pegawai"
                        className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                    >
                        <ArrowLeft className="size-4" />
                        Daftar pegawai
                    </Link>
                </div>

                <div className="space-y-2">
                    <Heading
                        title={employee.name}
                        description={
                            employee.employee_number
                                ? `Nomor induk ${employee.employee_number}`
                                : 'Belum ada nomor induk'
                        }
                    />
                    <div className="-mt-6 flex flex-wrap gap-1.5">
                        <Badge
                            variant={
                                employee.status === 'AKTIF'
                                    ? 'default'
                                    : 'outline'
                            }
                        >
                            {employee.status_label}
                        </Badge>
                        {lastPeriod && (
                            <Badge variant="secondary">
                                {lastPeriod.employment_type}
                            </Badge>
                        )}
                        {lastPeriod?.classification && (
                            <Badge variant="secondary">
                                {lastPeriod.classification}
                            </Badge>
                        )}
                        <Badge variant="outline">
                            {employee.has_account
                                ? 'Punya akun login'
                                : 'Belum punya akun login'}
                        </Badge>
                    </div>
                </div>

                {manage && (
                    <section aria-label="Kelola pegawai" className="space-y-3">
                        {/* Grid otomatis: tombol turun ke baris berikutnya di layar sempit. */}
                        <div className="grid grid-cols-[repeat(auto-fit,minmax(11rem,1fr))] gap-2">
                            <ActionButton
                                active={action === 'profile'}
                                onClick={() => toggle('profile')}
                                icon={<Pencil />}
                                label="Ubah profil"
                            />
                            {isActive ? (
                                <>
                                    <ActionButton
                                        active={action === 'status'}
                                        onClick={() => toggle('status')}
                                        icon={<BadgeCheck />}
                                        label="Ganti status"
                                    />
                                    <ActionButton
                                        active={action === 'addPlacement'}
                                        onClick={() => toggle('addPlacement')}
                                        icon={<MapPinPlus />}
                                        label="Tambah penempatan"
                                    />
                                    <ActionButton
                                        active={action === 'end'}
                                        onClick={() => toggle('end')}
                                        icon={<UserX />}
                                        label="Berhentikan"
                                        destructive
                                    />
                                </>
                            ) : (
                                <ActionButton
                                    active={action === 'rehire'}
                                    onClick={() => toggle('rehire')}
                                    icon={<RotateCcw />}
                                    label="Aktifkan kembali"
                                />
                            )}
                        </div>

                        {action === 'profile' && (
                            <ProfileForm
                                employeeId={employee.id}
                                manage={manage}
                                onDone={close}
                            />
                        )}
                        {action === 'status' && (
                            <StatusForm
                                employeeId={employee.id}
                                manage={manage}
                                onDone={close}
                            />
                        )}
                        {action === 'addPlacement' && (
                            <AddPlacementForm
                                employeeId={employee.id}
                                manage={manage}
                                onDone={close}
                            />
                        )}
                        {action === 'end' && (
                            <EndEmploymentForm
                                employeeId={employee.id}
                                manage={manage}
                                onDone={close}
                            />
                        )}
                        {action === 'rehire' && (
                            <RehireForm
                                employeeId={employee.id}
                                manage={manage}
                                onDone={close}
                            />
                        )}
                    </section>
                )}

                <Section title="Data kepegawaian">
                    <dl className="grid grid-cols-[repeat(auto-fit,minmax(12rem,1fr))] gap-4">
                        <Item
                            label="Masa kerja"
                            value={employee.service_length}
                        />
                        <Item label="NUPTK" value={employee.nuptk} mono />
                        <Item
                            label="Pendidikan terakhir"
                            value={employee.education}
                        />
                        <Item
                            label="Program studi/jurusan"
                            value={employee.education_major}
                        />
                        <Item label="Jenis kelamin" value={employee.gender} />
                    </dl>
                </Section>

                <Section title="Data pribadi">
                    {employee.personal?.nik_warning && (
                        <p className="text-xs text-amber-700 dark:text-amber-300">
                            {employee.personal.nik_warning}
                        </p>
                    )}
                    {employee.personal ? (
                        <dl className="grid grid-cols-[repeat(auto-fit,minmax(12rem,1fr))] gap-4">
                            <Item
                                label="NIK"
                                value={employee.personal.nik}
                                mono
                            />
                            <Item
                                label="Tempat lahir"
                                value={employee.personal.birth_place}
                            />
                            <Item
                                label="Tanggal lahir"
                                value={
                                    employee.personal.birth_date
                                        ? formatDate(
                                              employee.personal.birth_date,
                                          )
                                        : null
                                }
                            />
                            <Item
                                label="Nomor HP"
                                value={employee.personal.phone}
                                mono
                            />
                            <Item
                                label="Alamat"
                                value={employee.personal.address}
                            />
                        </dl>
                    ) : (
                        <p className="flex items-center gap-2 text-sm text-muted-foreground">
                            <Lock className="size-4 shrink-0" />
                            Data pribadi hanya untuk pemilik izin lihat data
                            pribadi pegawai.
                        </p>
                    )}
                </Section>

                <Section title="Penempatan berjalan">
                    {employee.placements.current.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Tidak ada penempatan yang berjalan.
                        </p>
                    ) : (
                        <ul className="divide-y">
                            {employee.placements.current.map((placement) => (
                                <li
                                    key={placement.id}
                                    className="space-y-3 py-3"
                                >
                                    <PlacementRow placement={placement} />

                                    {manage && isActive && (
                                        <div className="grid grid-cols-[repeat(auto-fit,minmax(9rem,1fr))] gap-2">
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                onClick={() => {
                                                    setAction(null);
                                                    setPlacementAction({
                                                        id: placement.id,
                                                        kind: 'transfer',
                                                    });
                                                }}
                                            >
                                                <ArrowRightLeft />
                                                Mutasi
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                onClick={() => {
                                                    setAction(null);
                                                    setPlacementAction({
                                                        id: placement.id,
                                                        kind: 'end',
                                                    });
                                                }}
                                            >
                                                Akhiri
                                            </Button>
                                            {!placement.is_primary && (
                                                <MakePrimaryButton
                                                    employeeId={employee.id}
                                                    placementId={placement.id}
                                                />
                                            )}
                                        </div>
                                    )}

                                    {manage &&
                                        placementAction?.id === placement.id &&
                                        placementAction.kind === 'transfer' && (
                                            <TransferForm
                                                employeeId={employee.id}
                                                placement={placement}
                                                manage={manage}
                                                onDone={close}
                                            />
                                        )}
                                    {manage &&
                                        placementAction?.id === placement.id &&
                                        placementAction.kind === 'end' && (
                                            <EndPlacementForm
                                                employeeId={employee.id}
                                                placement={placement}
                                                others={employee.placements.current.filter(
                                                    (other) =>
                                                        other.id !==
                                                        placement.id,
                                                )}
                                                manage={manage}
                                                onDone={close}
                                            />
                                        )}
                                </li>
                            ))}
                        </ul>
                    )}
                </Section>

                {leave && (
                    <LeaveSection
                        leave={leave}
                        employeeId={employee.id}
                        canManage={canManageLeave}
                        canRecord={canManageLeave && isActive}
                    />
                )}

                {attendance && (
                    <Section
                        title={`Presensi ${attendance.label} (s.d. ${attendance.until})`}
                    >
                        <AttendanceSummary summary={attendance} />
                    </Section>
                )}

                {access && <LoginAccessSection access={access} />}

                {employee.placements.history.length > 0 && (
                    <Section title="Riwayat penempatan">
                        <PlacementList items={employee.placements.history} />
                    </Section>
                )}

                <Section title="Riwayat kepegawaian">
                    {employee.periods.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Belum ada periode kepegawaian.
                        </p>
                    ) : (
                        <ol className="divide-y">
                            {employee.periods.map((period) => (
                                <li key={period.id} className="space-y-1 py-3">
                                    <div className="flex flex-wrap items-center gap-1.5">
                                        <span className="font-medium">
                                            {period.employment_type}
                                        </span>
                                        {period.classification && (
                                            <Badge variant="secondary">
                                                {period.classification}
                                            </Badge>
                                        )}
                                        {period.end_date === null && (
                                            <Badge>Berjalan</Badge>
                                        )}
                                    </div>
                                    <p className="text-sm text-muted-foreground">
                                        {formatDate(period.start_date)} –{' '}
                                        {period.end_date
                                            ? formatDate(period.end_date)
                                            : 'sekarang'}
                                    </p>
                                    {period.end_reason && (
                                        <p className="text-sm">
                                            Ditutup: {period.end_reason}
                                            {period.end_note &&
                                                ` — ${period.end_note}`}
                                        </p>
                                    )}
                                </li>
                            ))}
                        </ol>
                    )}
                </Section>
            </div>
        </>
    );
}

/**
 * Ringkasan cuti tahun berjalan (PRD-001 §13.5, HR-3d-b).
 */
function LeaveSection({
    leave,
    employeeId,
    canManage,
    canRecord,
}: {
    leave: LeaveSummary;
    employeeId: string;
    canManage: boolean;
    canRecord: boolean;
}) {
    return (
        <Section title={`Cuti ${leave.year}`}>
            {leave.balances.length > 0 && (
                <div className="grid grid-cols-[repeat(auto-fit,minmax(11rem,1fr))] gap-2">
                    {leave.balances.map((balance) => (
                        <div
                            key={balance.type_id}
                            className="rounded-md border p-3 text-sm"
                        >
                            <p className="text-muted-foreground">
                                {balance.name}
                            </p>
                            <p className="text-lg font-semibold">
                                Sisa {balance.remaining} dari{' '}
                                {balance.quota + balance.adjustment}
                            </p>
                            {balance.pending > 0 && (
                                <p className="text-muted-foreground">
                                    Menunggu persetujuan {balance.pending}
                                </p>
                            )}
                        </div>
                    ))}
                </div>
            )}

            {leave.recent.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    Belum ada pengajuan cuti.
                </p>
            ) : (
                <ul className="divide-y text-sm">
                    {leave.recent.map((row) => (
                        <li
                            key={row.id}
                            className="flex flex-wrap items-center justify-between gap-2 py-2"
                        >
                            <span className="min-w-0">
                                {canManage ? (
                                    <Link
                                        href={`/pegawai/cuti/${row.id}`}
                                        className="font-medium underline-offset-4 hover:underline"
                                    >
                                        {row.type}
                                    </Link>
                                ) : (
                                    <span className="font-medium">
                                        {row.type}
                                    </span>
                                )}{' '}
                                · {row.days} hari ·{' '}
                                {formatLeaveRange(row.start_date, row.end_date)}
                            </span>
                            <Badge
                                variant="outline"
                                className={LEAVE_STATUS_CLASS[row.status]}
                            >
                                {row.status_label}
                            </Badge>
                        </li>
                    ))}
                </ul>
            )}

            {canManage && (
                <div className="grid grid-cols-[repeat(auto-fit,minmax(11rem,1fr))] gap-2 sm:flex">
                    {canRecord && (
                        <Button variant="outline" asChild>
                            <Link
                                href={`/pegawai/cuti/catat?pegawai=${employeeId}`}
                            >
                                Catat cuti
                            </Link>
                        </Button>
                    )}
                    <Button variant="outline" asChild>
                        <Link
                            href={`/pegawai/cuti/saldo?pegawai=${employeeId}`}
                        >
                            Saldo & penyesuaian
                        </Link>
                    </Button>
                </div>
            )}
        </Section>
    );
}

function Section({
    title,
    children,
}: {
    title: string;
    children: React.ReactNode;
}) {
    return (
        <section className="space-y-3 rounded-xl border p-4">
            <h2 className="text-base font-medium">{title}</h2>
            {children}
        </section>
    );
}

function Item({
    label,
    value,
    mono = false,
}: {
    label: string;
    value: string | null;
    mono?: boolean;
}) {
    return (
        <div className="min-w-0 space-y-0.5">
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd
                className={
                    'text-sm break-words ' + (mono && value ? 'font-mono' : '')
                }
            >
                {value ?? '–'}
            </dd>
        </div>
    );
}

function ActionButton({
    active,
    onClick,
    icon,
    label,
    destructive = false,
}: {
    active: boolean;
    onClick: () => void;
    icon: React.ReactNode;
    label: string;
    destructive?: boolean;
}) {
    return (
        <Button
            type="button"
            variant={active ? 'default' : 'outline'}
            onClick={onClick}
            aria-expanded={active}
            className={destructive && !active ? 'text-destructive' : undefined}
        >
            {icon}
            {label}
        </Button>
    );
}

function PlacementList({ items }: { items: Placement[] }) {
    return (
        <ul className="divide-y">
            {items.map((placement) => (
                <li key={placement.id} className="py-3">
                    <PlacementRow placement={placement} />
                </li>
            ))}
        </ul>
    );
}

function PlacementRow({ placement }: { placement: Placement }) {
    return (
        <div className="flex flex-wrap items-start justify-between gap-2">
            <div className="min-w-0">
                <p className="font-medium break-words">{placement.position}</p>
                <p className="text-sm text-muted-foreground">
                    {placement.organization} (
                    <span className="font-mono">
                        {placement.organization_code}
                    </span>
                    )
                </p>
                <p className="text-xs text-muted-foreground">
                    {formatDate(placement.start_date)} –{' '}
                    {placement.end_date
                        ? formatDate(placement.end_date)
                        : 'sekarang'}
                </p>
            </div>
            {placement.is_primary && <Badge variant="secondary">Utama</Badge>}
        </div>
    );
}
