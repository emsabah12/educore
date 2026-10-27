import { Head, Link, router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import type { PlacementRef } from '@/components/hr/pay-format';
import { formatRupiah } from '@/components/hr/pay-format';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Option = { value: string; label: string };

type Row = {
    id: string;
    name: string;
    employee_number: string | null;
    placements: PlacementRef[];
    position_total: number;
    monthly_earning: number;
    monthly_deduction: number;
    per_present_day: number;
    configured: boolean;
};

type Props = {
    rows: Row[];
    pagination: {
        current_page: number;
        last_page: number;
        total: number;
        prev_url: string | null;
        next_url: string | null;
    };
    filters: { cari: string; lembaga: string | null; belum: boolean };
    organizations: Option[];
};

const BASE_URL = '/pegawai/gaji';

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';

/**
 * Gaji pegawai (PRD-001 §16.1, HR-5a-2): daftar pegawai aktif dengan ringkasan gaji tetap. Setup per
 * pegawai di halaman tersendiri. Hanya Admin SDM / Admin Yayasan.
 */
export default function PayrollEmployees({
    rows,
    pagination,
    filters,
    organizations,
}: Props) {
    const [search, setSearch] = useState(filters.cari);

    const go = (next: Partial<Props['filters']>) =>
        router.get(
            BASE_URL,
            {
                cari: next.cari ?? filters.cari,
                lembaga: (next.lembaga ?? filters.lembaga) || undefined,
                belum: (next.belum ?? filters.belum) ? 1 : undefined,
            },
            { preserveState: true, replace: true },
        );

    return (
        <>
            <Head title="Gaji pegawai" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                    <Heading
                        title="Gaji pegawai"
                        description="Gaji tetap per bulan: dari tarif jabatan dan komponen per pegawai. Honor JP, potongan presensi, dan uang per hari hadir dihitung di rekap bulanan."
                    />
                    <Button variant="outline" asChild>
                        <Link href="/pegawai/gaji/pengaturan">
                            Pengaturan gaji
                        </Link>
                    </Button>
                </div>

                <div className="grid grid-cols-[repeat(auto-fit,minmax(12rem,1fr))] gap-3 rounded-xl border p-4">
                    <form
                        className="grid gap-1.5"
                        onSubmit={(event) => {
                            event.preventDefault();
                            go({ cari: search });
                        }}
                    >
                        <Label htmlFor="cari">Cari nama / nomor induk</Label>
                        <div className="flex gap-2">
                            <Input
                                id="cari"
                                value={search}
                                onChange={(event) =>
                                    setSearch(event.target.value)
                                }
                                maxLength={100}
                            />
                            <Button
                                type="submit"
                                variant="outline"
                                size="icon"
                                aria-label="Cari"
                            >
                                <Search />
                            </Button>
                        </div>
                    </form>
                    <div className="grid gap-1.5">
                        <Label htmlFor="lembaga">Lembaga</Label>
                        <select
                            id="lembaga"
                            className={selectClass}
                            value={filters.lembaga ?? ''}
                            onChange={(event) =>
                                go({ lembaga: event.target.value })
                            }
                        >
                            <option value="">Semua lembaga</option>
                            {organizations.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <label className="flex cursor-pointer items-center gap-2 self-end pb-2 text-sm">
                        <input
                            type="checkbox"
                            checked={filters.belum}
                            onChange={(event) =>
                                go({ belum: event.target.checked })
                            }
                            className="size-4 accent-primary"
                        />
                        Hanya yang belum punya komponen per pegawai
                    </label>
                </div>

                <p className="text-sm text-muted-foreground">
                    {pagination.total} pegawai aktif
                </p>

                {rows.length === 0 ? (
                    <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                        Tidak ada pegawai yang cocok dengan saringan.
                    </p>
                ) : (
                    <ul className="divide-y rounded-xl border">
                        {rows.map((row) => (
                            <li key={row.id}>
                                <Link
                                    href={`${BASE_URL}/${row.id}`}
                                    className="flex flex-col gap-2 p-3 text-sm hover:bg-muted/50 lg:flex-row lg:items-center lg:justify-between"
                                >
                                    <div className="min-w-0 space-y-1">
                                        <p className="flex flex-wrap items-center gap-2 font-medium break-words">
                                            {row.name}
                                            {!row.configured && (
                                                <Badge className="bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-100">
                                                    Belum diatur
                                                </Badge>
                                            )}
                                        </p>
                                        <p className="text-xs break-words text-muted-foreground">
                                            {[
                                                row.employee_number,
                                                ...row.placements.map(
                                                    (placement) =>
                                                        `${placement.position} — ${placement.organization}`,
                                                ),
                                            ]
                                                .filter(Boolean)
                                                .join(' · ') ||
                                                'Tanpa penempatan'}
                                        </p>
                                    </div>
                                    <dl className="grid shrink-0 grid-cols-[repeat(auto-fit,minmax(8rem,1fr))] gap-2 text-xs lg:w-[30rem]">
                                        <Amount
                                            label="Dari jabatan"
                                            value={row.position_total}
                                        />
                                        <Amount
                                            label="Pendapatan tetap"
                                            value={row.monthly_earning}
                                        />
                                        <Amount
                                            label="Potongan tetap"
                                            value={row.monthly_deduction}
                                        />
                                        <Amount
                                            label="Per hari hadir"
                                            value={row.per_present_day}
                                        />
                                    </dl>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}

                {pagination.last_page > 1 && (
                    <div className="flex items-center justify-between gap-2 text-sm">
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={!pagination.prev_url}
                            asChild={Boolean(pagination.prev_url)}
                        >
                            {pagination.prev_url ? (
                                <Link href={pagination.prev_url}>
                                    Sebelumnya
                                </Link>
                            ) : (
                                <span>Sebelumnya</span>
                            )}
                        </Button>
                        <span className="text-muted-foreground">
                            Halaman {pagination.current_page} dari{' '}
                            {pagination.last_page}
                        </span>
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={!pagination.next_url}
                            asChild={Boolean(pagination.next_url)}
                        >
                            {pagination.next_url ? (
                                <Link href={pagination.next_url}>
                                    Berikutnya
                                </Link>
                            ) : (
                                <span>Berikutnya</span>
                            )}
                        </Button>
                    </div>
                )}
            </div>
        </>
    );
}

function Amount({ label, value }: { label: string; value: number }) {
    return (
        <div>
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="font-medium tabular-nums">{formatRupiah(value)}</dd>
        </div>
    );
}
