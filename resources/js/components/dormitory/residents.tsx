import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, TriangleAlert } from 'lucide-react';
import type { ReactNode } from 'react';
import { Empty } from '@/components/dormitory/facility-forms';
import type { PlacementPage, PlacementRow } from '@/components/dormitory/types';
import { dateText } from '@/components/dormitory/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

/** Badge status penempatan (PRD-008 §5.2). */
export function PlacementStatusBadge({ row }: { row: PlacementRow }) {
    if (row.status === 'AKTIF') {
        return <Badge variant="secondary">Tinggal</Badge>;
    }

    if (row.status === 'DIRENCANAKAN') {
        return <Badge variant="outline">Rencana</Badge>;
    }

    return (
        <Badge variant="outline" className="text-muted-foreground">
            {row.status_label}
        </Badge>
    );
}

export function resourceText(row: PlacementRow) {
    const parts = [
        row.bed ? `Bed ${row.bed}` : null,
        row.locker ? `Loker ${row.locker}` : null,
    ].filter(Boolean);

    return parts.length > 0 ? parts.join(' · ') : 'Tanpa bed/loker';
}

export function dateLine(row: PlacementRow) {
    if (row.status === 'DIRENCANAKAN') {
        return row.planned_on
            ? `Rencana masuk ${dateText(row.planned_on)}`
            : 'Rencana masuk belum ditentukan';
    }

    if (row.status === 'AKTIF') {
        return `Masuk ${dateText(row.check_in_on)}`;
    }

    if (row.status === 'SELESAI') {
        return `${dateText(row.check_in_on)} – ${dateText(row.check_out_on)}`;
    }

    return 'Dibatalkan';
}

export function ResourceWarning({ row }: { row: PlacementRow }) {
    if (!row.resource_problem) {
        return null;
    }

    return (
        <p className="flex items-center gap-1 text-xs text-destructive">
            <TriangleAlert className="size-3.5" />
            Bed/loker yang dipegang sedang tidak bisa dipakai. Ganti bed/loker.
        </p>
    );
}

/**
 * Daftar penghuni lintas kamar (menu Penghuni & tab Penghuni di detail asrama): tabel di layar lebar, kartu di HP.
 */
export function ResidentList({
    page,
    showDormitory,
    emptyText,
}: {
    page: PlacementPage;
    showDormitory: boolean;
    emptyText: string;
}) {
    if (page.data.length === 0) {
        return <Empty>{emptyText}</Empty>;
    }

    return (
        <div className="space-y-3">
            <div className="hidden overflow-x-auto rounded-xl border md:block">
                <table className="w-full text-sm">
                    <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                        <tr>
                            <th className="px-4 py-2 font-medium">Nama</th>
                            <th className="px-4 py-2 font-medium">Kamar</th>
                            <th className="px-4 py-2 font-medium">
                                Bed / loker
                            </th>
                            <th className="px-4 py-2 font-medium">Status</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y">
                        {page.data.map((row) => (
                            <tr key={row.id} className="align-top">
                                <td className="px-4 py-2.5">
                                    <PersonLink row={row} />
                                    <p className="text-xs text-muted-foreground">
                                        {row.category === 'PENGAWAS'
                                            ? 'Pengawas'
                                            : row.detail}
                                    </p>
                                </td>
                                <td className="px-4 py-2.5">
                                    <RoomLink row={row} />
                                    <p className="text-xs text-muted-foreground">
                                        {showDormitory
                                            ? `${row.building} · ${row.dormitory}`
                                            : row.building}
                                    </p>
                                </td>
                                <td className="px-4 py-2.5">
                                    {resourceText(row)}
                                    <ResourceWarning row={row} />
                                </td>
                                <td className="px-4 py-2.5">
                                    <PlacementStatusBadge row={row} />
                                    <p className="text-xs text-muted-foreground">
                                        {dateLine(row)}
                                    </p>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <ul className="grid gap-2 md:hidden">
                {page.data.map((row) => (
                    <li
                        key={row.id}
                        className="space-y-1 rounded-xl border p-3"
                    >
                        <div className="flex items-start justify-between gap-2">
                            <PersonLink row={row} />
                            <PlacementStatusBadge row={row} />
                        </div>
                        <p className="text-xs text-muted-foreground">
                            {row.category === 'PENGAWAS'
                                ? 'Pengawas'
                                : row.detail}
                        </p>
                        <p className="text-sm">
                            <RoomLink row={row} /> · {resourceText(row)}
                        </p>
                        <p className="text-xs text-muted-foreground">
                            {showDormitory
                                ? `${row.building} · ${row.dormitory}`
                                : row.building}{' '}
                            · {dateLine(row)}
                        </p>
                        <ResourceWarning row={row} />
                    </li>
                ))}
            </ul>

            <Pagination page={page} label="penghuni" />
        </div>
    );
}

function PersonLink({ row }: { row: PlacementRow }) {
    return (
        <Link
            href={`/asrama/penghuni/${row.membership_id}`}
            className="font-medium break-words hover:underline"
        >
            {row.name}
        </Link>
    );
}

function RoomLink({ row }: { row: PlacementRow }) {
    return (
        <Link
            href={`/asrama/kamar/${row.room_id}`}
            className="font-medium hover:underline"
        >
            {row.room}
        </Link>
    );
}

export function Pagination({
    page,
    label,
}: {
    page: PlacementPage;
    label: string;
}) {
    if (page.total === 0) {
        return null;
    }

    return (
        <nav
            aria-label={`Halaman daftar ${label}`}
            className="flex flex-wrap items-center justify-between gap-3"
        >
            <p className="text-sm text-muted-foreground">
                {page.from}–{page.to} dari {page.total} {label}
            </p>
            {page.last_page > 1 && (
                <div className="flex gap-2">
                    <PageLink href={page.prev_page_url} label="Sebelumnya">
                        <ChevronLeft />
                        Sebelumnya
                    </PageLink>
                    <PageLink href={page.next_page_url} label="Berikutnya">
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
