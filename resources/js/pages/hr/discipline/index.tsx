import { Head, Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import Heading from '@/components/heading';
import {
    DisciplineStatusBadge,
    formatDisciplineDate,
} from '@/components/hr/employee-discipline';
import type { DisciplineStatus } from '@/components/hr/employee-discipline';
import { selectClass } from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

type Option = { value: string; label: string };

type Row = {
    id: string;
    employee_id: string;
    employee_name: string;
    employee_number: string | null;
    organization: string | null;
    kind: string;
    kind_label: string;
    issued_on: string;
    valid_until: string | null;
    status: DisciplineStatus;
};

type Props = {
    records: {
        data: Row[];
        from: number | null;
        to: number | null;
        total: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    filters: { lembaga: string | null; jenis: string | null; status: string };
    options: { organizations: Option[]; kinds: Option[] };
};

/**
 * Disiplin pegawai (PRD-001 §18.1, HR-7a): daftar catatan disiplin yang terlihat sesuai cakupan (Kepala Lembaga →
 * pegawai lembaganya). Mencatat & mencabut dilakukan di detail pegawai → tab Disiplin.
 */
export default function DisciplineIndex({ records, filters, options }: Props) {
    const apply = (change: Partial<Props['filters']>) => {
        const next = { ...filters, ...change };

        router.get(
            '/pegawai/disiplin',
            {
                lembaga: next.lembaga ?? undefined,
                jenis: next.jenis ?? undefined,
                status: next.status,
            },
            { preserveScroll: true, replace: true },
        );
    };

    return (
        <>
            <Head title="Disiplin pegawai" />

            <div className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-4 p-4">
                <Heading
                    title="Disiplin pegawai"
                    description="Teguran dan surat peringatan pegawai. Catat atau cabut lewat detail pegawai → tab Disiplin."
                />

                <div className="grid grid-cols-[repeat(auto-fit,minmax(12rem,1fr))] gap-3 rounded-xl border p-4">
                    <div className="grid gap-1.5">
                        <Label htmlFor="filter-org">Lembaga</Label>
                        <select
                            id="filter-org"
                            className={selectClass}
                            value={filters.lembaga ?? ''}
                            onChange={(event) =>
                                apply({ lembaga: event.target.value || null })
                            }
                        >
                            <option value="">Semua lembaga</option>
                            {options.organizations.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="filter-kind">Jenis</Label>
                        <select
                            id="filter-kind"
                            className={selectClass}
                            value={filters.jenis ?? ''}
                            onChange={(event) =>
                                apply({ jenis: event.target.value || null })
                            }
                        >
                            <option value="">Semua jenis</option>
                            {options.kinds.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="filter-status">Status</Label>
                        <select
                            id="filter-status"
                            className={selectClass}
                            value={filters.status}
                            onChange={(event) =>
                                apply({ status: event.target.value })
                            }
                        >
                            <option value="berlaku">Masih berlaku saja</option>
                            <option value="semua">Semua catatan</option>
                        </select>
                    </div>
                </div>

                {records.data.length === 0 ? (
                    <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                        {filters.status === 'berlaku'
                            ? 'Tidak ada catatan disiplin yang masih berlaku.'
                            : 'Belum ada catatan disiplin.'}
                    </p>
                ) : (
                    <ul className="divide-y rounded-xl border">
                        {records.data.map((row) => (
                            <li key={row.id}>
                                <Link
                                    href={`/pegawai/${row.employee_id}?tab=disiplin`}
                                    className="flex flex-wrap items-center justify-between gap-2 p-3 text-sm hover:bg-muted/40"
                                >
                                    <div className="min-w-0">
                                        <p className="font-medium break-words">
                                            {row.employee_name}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {[
                                                row.employee_number,
                                                row.organization ??
                                                    'Tanpa penempatan utama',
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </p>
                                    </div>
                                    <div className="flex flex-wrap items-center gap-2 text-xs">
                                        <span className="font-medium">
                                            {row.kind_label}
                                        </span>
                                        <span className="text-muted-foreground">
                                            {formatDisciplineDate(
                                                row.issued_on,
                                            )}
                                            {row.valid_until &&
                                                ` – ${formatDisciplineDate(row.valid_until)}`}
                                        </span>
                                        <DisciplineStatusBadge
                                            status={row.status}
                                        />
                                    </div>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}

                {records.total > 0 && (
                    <nav
                        aria-label="Halaman"
                        className="flex flex-wrap items-center justify-between gap-3"
                    >
                        <p className="text-sm text-muted-foreground">
                            {records.from}–{records.to} dari {records.total}{' '}
                            catatan
                        </p>
                        {records.last_page > 1 && (
                            <div className="flex gap-2">
                                <PageLink href={records.prev_page_url}>
                                    <ChevronLeft />
                                    Sebelumnya
                                </PageLink>
                                <PageLink href={records.next_page_url}>
                                    Berikutnya
                                    <ChevronRight />
                                </PageLink>
                            </div>
                        )}
                    </nav>
                )}
            </div>
        </>
    );
}

function PageLink({
    href,
    children,
}: {
    href: string | null;
    children: React.ReactNode;
}) {
    if (!href) {
        return (
            <Button variant="outline" size="sm" disabled>
                {children}
            </Button>
        );
    }

    return (
        <Button variant="outline" size="sm" asChild>
            <Link href={href} preserveScroll>
                {children}
            </Link>
        </Button>
    );
}
