import { Head, Link, router } from '@inertiajs/react';
import Heading from '@/components/heading';
import { selectClass } from '@/components/native-select';
import { Badge } from '@/components/ui/badge';
import { Label } from '@/components/ui/label';

type Row = {
    employee_id: string;
    employee_name: string;
    employee_number: string | null;
    organization: string | null;
    end_date: string;
    reason: string | null;
    done: number;
    total: number;
    completed: boolean;
};

type Props = {
    rows: Row[];
    filter: 'berjalan' | 'semua';
};

const dateFormat = new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium' });

function formatDate(value: string): string {
    const [year, month, day] = value.slice(0, 10).split('-').map(Number);

    return dateFormat.format(new Date(year, month - 1, day));
}

/**
 * Pegawai keluar (PRD-001 §18.2, HR-7b): kemajuan checklist offboarding tiap pegawai yang berhenti. Hanya Admin
 * SDM / Admin Yayasan. Mengisi checklist lewat detail pegawai → tab Offboarding.
 */
export default function OffboardingIndex({ rows, filter }: Props) {
    return (
        <>
            <Head title="Pegawai keluar" />

            <div className="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-4 p-4">
                <Heading
                    title="Pegawai keluar"
                    description="Checklist offboarding pegawai yang diberhentikan: pengembalian inventaris, serah terima, akun login, hak terakhir."
                />

                <div className="grid max-w-xs gap-1.5">
                    <Label htmlFor="offboarding-filter">Tampilkan</Label>
                    <select
                        id="offboarding-filter"
                        className={selectClass}
                        value={filter}
                        onChange={(event) =>
                            router.get(
                                '/pegawai/keluar',
                                { status: event.target.value },
                                { preserveScroll: true, replace: true },
                            )
                        }
                    >
                        <option value="berjalan">
                            Checklist yang belum selesai
                        </option>
                        <option value="semua">Semua</option>
                    </select>
                </div>

                {rows.length === 0 ? (
                    <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                        {filter === 'berjalan'
                            ? 'Tidak ada checklist offboarding yang belum selesai.'
                            : 'Belum ada pegawai yang diberhentikan dengan checklist offboarding.'}
                    </p>
                ) : (
                    <ul className="divide-y rounded-xl border">
                        {rows.map((row) => (
                            <li key={`${row.employee_id}-${row.end_date}`}>
                                <Link
                                    href={`/pegawai/${row.employee_id}?tab=offboarding`}
                                    className="flex flex-wrap items-center justify-between gap-2 p-3 text-sm hover:bg-muted/40"
                                >
                                    <div className="min-w-0">
                                        <p className="font-medium break-words">
                                            {row.employee_name}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {[
                                                row.organization,
                                                `berhenti ${formatDate(row.end_date)}`,
                                                row.reason,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </p>
                                    </div>
                                    <div className="flex items-center gap-3">
                                        <div
                                            className="h-2 w-24 overflow-hidden rounded-full bg-muted"
                                            role="progressbar"
                                            aria-valuenow={row.done}
                                            aria-valuemin={0}
                                            aria-valuemax={row.total}
                                            aria-label={`Kemajuan ${row.employee_name}`}
                                        >
                                            <div
                                                className="h-full bg-primary"
                                                style={{
                                                    width: `${row.total > 0 ? Math.round((row.done / row.total) * 100) : 100}%`,
                                                }}
                                            />
                                        </div>
                                        <Badge
                                            variant={
                                                row.completed
                                                    ? 'default'
                                                    : 'outline'
                                            }
                                        >
                                            {row.completed
                                                ? 'Selesai'
                                                : `${row.done}/${row.total}`}
                                        </Badge>
                                    </div>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}
