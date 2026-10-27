import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Download } from 'lucide-react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

type Option = { value: string; label: string };

type Group = { label: string; count: number };

type GroupKey =
    | 'organization'
    | 'employment_type'
    | 'classification'
    | 'position'
    | 'gender'
    | 'education';

type Props = {
    report: {
        total: number;
        ended_this_year: number;
        year: number;
        groups: Record<GroupKey, Group[]>;
    };
    titles: Record<GroupKey, string>;
    organization: string | null;
    organizations: Option[];
    today: string;
};

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';

/**
 * Laporan pegawai (PRD-001 §17, HR-6a): jumlah pegawai aktif per pengelompokan, sesuai cakupan lihat
 * pengguna (Kepala Lembaga hanya lembaganya).
 */
export default function EmployeeReport({
    report,
    titles,
    organization,
    organizations,
    today,
}: Props) {
    const query = organization ? `?lembaga=${organization}` : '';

    return (
        <>
            <Head title="Laporan pegawai" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <Button
                    variant="ghost"
                    size="sm"
                    asChild
                    className="self-start"
                >
                    <Link href="/pegawai">
                        <ArrowLeft />
                        Pegawai
                    </Link>
                </Button>

                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <Heading
                        title="Laporan pegawai"
                        description={`Jumlah pegawai aktif per ${today}, hanya dari lembaga yang bisa Anda lihat. Jabatan dihitung per penempatan (rangkap jabatan terhitung di tiap jabatan).`}
                    />
                    <Button variant="outline" asChild>
                        <a href={`/pegawai/laporan/unduh${query}`}>
                            <Download />
                            Unduh .xlsx
                        </a>
                    </Button>
                </div>

                <div className="grid max-w-md gap-1.5">
                    <Label htmlFor="lembaga">Lembaga</Label>
                    <select
                        id="lembaga"
                        className={selectClass}
                        value={organization ?? ''}
                        onChange={(event) =>
                            router.get(
                                '/pegawai/laporan',
                                { lembaga: event.target.value || undefined },
                                { preserveState: false, replace: true },
                            )
                        }
                    >
                        <option value="">Semua lembaga yang terlihat</option>
                        {organizations.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </select>
                </div>

                <div className="grid grid-cols-[repeat(auto-fit,minmax(11rem,1fr))] gap-3">
                    <Stat label="Pegawai aktif" value={report.total} />
                    <Stat
                        label={`Berhenti tahun ${report.year}`}
                        value={report.ended_this_year}
                    />
                </div>

                {report.total === 0 ? (
                    <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                        Tidak ada pegawai aktif di cakupan ini.
                    </p>
                ) : (
                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {(Object.keys(titles) as GroupKey[]).map((key) => (
                            <GroupCard
                                key={key}
                                title={titles[key]}
                                groups={report.groups[key]}
                            />
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

function Stat({ label, value }: { label: string; value: number }) {
    return (
        <div className="rounded-xl border p-4">
            <p className="text-sm text-muted-foreground">{label}</p>
            <p className="text-3xl font-semibold tabular-nums">{value}</p>
        </div>
    );
}

function GroupCard({ title, groups }: { title: string; groups: Group[] }) {
    const max = Math.max(1, ...groups.map((group) => group.count));

    return (
        <section aria-label={title} className="space-y-3 rounded-xl border p-4">
            <h2 className="text-sm font-medium">{title}</h2>
            {groups.length === 0 ? (
                <p className="text-sm text-muted-foreground">Tidak ada data.</p>
            ) : (
                <ul className="space-y-2 text-sm">
                    {groups.map((group) => (
                        <li key={group.label} className="space-y-1">
                            <div className="flex items-center justify-between gap-2">
                                <span className="min-w-0 break-words">
                                    {group.label}
                                </span>
                                <span className="font-medium tabular-nums">
                                    {group.count}
                                </span>
                            </div>
                            <div className="h-1.5 rounded-full bg-muted">
                                <div
                                    className="h-1.5 rounded-full bg-primary"
                                    style={{
                                        width: `${(group.count / max) * 100}%`,
                                    }}
                                />
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
