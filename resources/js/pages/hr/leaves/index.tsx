import { Head, Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Plus } from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import Heading from '@/components/heading';
import {
    formatLeaveRange,
    LEAVE_STATUS_CLASS,
} from '@/components/hr/leave-format';
import type { LeaveStatus } from '@/components/hr/leave-format';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Option = { value: string; label: string };

type LeaveRow = {
    id: string;
    employee_id: string;
    employee: string;
    type: string;
    organization: string;
    start_date: string;
    end_date: string;
    days: number;
    status: LeaveStatus;
    status_label: string;
    recorded: boolean;
};

type Filters = {
    lembaga: string;
    jenis: string;
    status: string;
    bulan: string;
};

type Props = {
    leaves: {
        data: LeaveRow[];
        current_page: number;
        last_page: number;
        total: number;
        from: number | null;
        to: number | null;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    filters: Filters;
    options: { organizations: Option[]; types: Option[]; statuses: Option[] };
};

const BASE_URL = '/pegawai/cuti';

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';

/**
 * Kelola cuti (PRD-001 §13.5, HR-3d): semua pengajuan cuti di yayasan, untuk Admin SDM.
 */
export default function Leaves({ leaves, filters, options }: Props) {
    const applyFilters = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        const data = new FormData(event.currentTarget);
        const query: Record<string, string> = {};

        for (const [key, value] of data.entries()) {
            if (typeof value === 'string' && value.trim() !== '') {
                query[key] = value.trim();
            }
        }

        router.get(BASE_URL, query, { preserveState: true, replace: true });
    };

    const isFiltered = Object.values(filters).some((value) => value !== '');

    return (
        <>
            <Head title="Kelola cuti" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <Heading
                        title="Kelola cuti"
                        description="Semua pengajuan cuti di yayasan. Keputusan tahap persetujuan ada di menu Persetujuan cuti."
                    />
                    <Button asChild className="w-full sm:w-auto">
                        <Link href={`${BASE_URL}/catat`}>
                            <Plus />
                            Catat cuti
                        </Link>
                    </Button>
                </div>

                {/* Grid otomatis: kolom saringan turun ke baris berikutnya di layar sempit. */}
                <form
                    key={JSON.stringify(filters)}
                    onSubmit={applyFilters}
                    className="grid grid-cols-[repeat(auto-fit,minmax(11rem,1fr))] items-end gap-3 rounded-xl border p-4"
                >
                    <FilterSelect
                        id="lembaga"
                        label="Lembaga / unit"
                        allLabel="Semua lembaga"
                        options={options.organizations}
                        value={filters.lembaga}
                    />
                    <FilterSelect
                        id="jenis"
                        label="Jenis"
                        allLabel="Semua jenis"
                        options={options.types}
                        value={filters.jenis}
                    />
                    <FilterSelect
                        id="status"
                        label="Status"
                        allLabel="Semua status"
                        options={options.statuses}
                        value={filters.status}
                    />
                    <div className="grid gap-1.5">
                        <Label htmlFor="bulan">Bulan</Label>
                        <Input
                            id="bulan"
                            name="bulan"
                            type="month"
                            defaultValue={filters.bulan}
                        />
                    </div>
                    <div className="grid grid-cols-[repeat(auto-fit,minmax(6rem,1fr))] gap-2">
                        <Button type="submit">Terapkan</Button>
                        {isFiltered && (
                            <Button variant="outline" asChild>
                                <Link href={BASE_URL}>Hapus</Link>
                            </Button>
                        )}
                    </div>
                </form>

                {leaves.data.length === 0 ? (
                    <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                        {isFiltered
                            ? 'Tidak ada pengajuan yang cocok dengan saringan.'
                            : 'Belum ada pengajuan cuti.'}
                    </p>
                ) : (
                    <ul className="divide-y rounded-xl border">
                        {leaves.data.map((row) => (
                            <li key={row.id}>
                                <Link
                                    href={`${BASE_URL}/${row.id}`}
                                    className="flex flex-wrap items-start justify-between gap-2 p-4 text-sm hover:bg-muted/50"
                                >
                                    <div className="min-w-0 space-y-0.5">
                                        <p className="font-medium break-words">
                                            {row.employee}
                                        </p>
                                        <p className="text-muted-foreground">
                                            {row.organization}
                                        </p>
                                        <p>
                                            {row.type} · {row.days} hari ·{' '}
                                            {formatLeaveRange(
                                                row.start_date,
                                                row.end_date,
                                            )}
                                        </p>
                                    </div>
                                    <div className="flex flex-wrap items-center gap-1.5">
                                        {row.recorded && (
                                            <Badge variant="outline">
                                                Dicatat SDM
                                            </Badge>
                                        )}
                                        <Badge
                                            variant="outline"
                                            className={
                                                LEAVE_STATUS_CLASS[row.status]
                                            }
                                        >
                                            {row.status_label}
                                        </Badge>
                                    </div>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}

                <Pagination leaves={leaves} />
            </div>
        </>
    );
}

function FilterSelect({
    id,
    label,
    allLabel,
    options,
    value,
}: {
    id: string;
    label: string;
    allLabel: string;
    options: Option[];
    value: string;
}) {
    return (
        <div className="grid gap-1.5">
            <Label htmlFor={id}>{label}</Label>
            <select
                id={id}
                name={id}
                className={selectClass}
                defaultValue={value}
            >
                <option value="">{allLabel}</option>
                {options.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
        </div>
    );
}

function Pagination({ leaves }: { leaves: Props['leaves'] }) {
    if (leaves.total === 0) {
        return null;
    }

    return (
        <nav
            aria-label="Halaman daftar cuti"
            className="flex flex-wrap items-center justify-between gap-3"
        >
            <p className="text-sm text-muted-foreground">
                {leaves.from}–{leaves.to} dari {leaves.total} pengajuan
            </p>
            {leaves.last_page > 1 && (
                <div className="flex gap-2">
                    <PageLink href={leaves.prev_page_url} label="Sebelumnya">
                        <ChevronLeft />
                        Sebelumnya
                    </PageLink>
                    <PageLink href={leaves.next_page_url} label="Berikutnya">
                        Berikutnya
                        <ChevronRight />
                    </PageLink>
                </div>
            )}
        </nav>
    );
}

function PageLink({
    href,
    label,
    children,
}: {
    href: string | null;
    label: string;
    children: ReactNode;
}) {
    if (!href) {
        return (
            <Button variant="outline" size="sm" disabled aria-label={label}>
                {children}
            </Button>
        );
    }

    return (
        <Button variant="outline" size="sm" asChild>
            <Link href={href} preserveScroll aria-label={label}>
                {children}
            </Link>
        </Button>
    );
}
