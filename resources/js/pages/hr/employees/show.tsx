import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Lock } from 'lucide-react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';

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
};

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
 * Detail pegawai (PRD-001 §6), baca-saja. Aksi ubah/mutasi/berhenti menyusul di HR-1d.
 */
export default function EmployeeDetail({ employee }: Props) {
    const lastPeriod = employee.periods[0];

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
                    {employee.personal ? (
                        <dl className="grid grid-cols-[repeat(auto-fit,minmax(12rem,1fr))] gap-4">
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
                        <PlacementList items={employee.placements.current} />
                    )}
                </Section>

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

function PlacementList({ items }: { items: Placement[] }) {
    return (
        <ul className="divide-y">
            {items.map((placement) => (
                <li
                    key={placement.id}
                    className="flex flex-wrap items-start justify-between gap-2 py-3"
                >
                    <div className="min-w-0">
                        <p className="font-medium break-words">
                            {placement.position}
                        </p>
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
                    {placement.is_primary && (
                        <Badge variant="secondary">Utama</Badge>
                    )}
                </li>
            ))}
        </ul>
    );
}
