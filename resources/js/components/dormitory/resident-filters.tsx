import { router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useState } from 'react';
import type { Option, ResidentFilters } from '@/components/dormitory/types';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { selectClass } from '@/components/native-select';

/**
 * Saringan daftar penghuni (asrama, gedung, status, kategori, nama). Alamat memakai kunci berbahasa Indonesia
 * (asrama, gedung, status, kategori, q); `extra` dipakai tab Penghuni di detail asrama (tab=penghuni).
 */
export function ResidentFilterBar({
    url,
    filters,
    dormitories,
    buildings,
    categories,
    extra = {},
}: {
    url: string;
    filters: ResidentFilters;
    dormitories?: Option[];
    buildings: Option[];
    categories: Option[];
    extra?: Record<string, string>;
}) {
    const [q, setQ] = useState(filters.q);

    const visit = (next: Partial<ResidentFilters>) => {
        const merged = { ...filters, q, ...next };

        router.get(
            url,
            {
                ...extra,
                ...(dormitories && merged.dormitory
                    ? { asrama: merged.dormitory }
                    : {}),
                ...(merged.building ? { gedung: merged.building } : {}),
                ...(merged.status !== 'semua' ? { status: merged.status } : {}),
                ...(merged.category ? { kategori: merged.category } : {}),
                ...(merged.q ? { q: merged.q } : {}),
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    return (
        <div className="grid grid-cols-[repeat(auto-fit,minmax(11rem,1fr))] items-end gap-3">
            {dormitories && dormitories.length > 1 && (
                <div className="grid gap-1.5">
                    <Label htmlFor="rs-dorm">Asrama</Label>
                    <select
                        id="rs-dorm"
                        className={selectClass}
                        value={filters.dormitory ?? ''}
                        onChange={(event) =>
                            visit({
                                dormitory: event.target.value || null,
                                building: null,
                            })
                        }
                    >
                        <option value="">Semua asrama</option>
                        {dormitories.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </select>
                </div>
            )}
            {buildings.length > 0 && (
                <div className="grid gap-1.5">
                    <Label htmlFor="rs-building">Gedung</Label>
                    <select
                        id="rs-building"
                        className={selectClass}
                        value={filters.building ?? ''}
                        onChange={(event) =>
                            visit({ building: event.target.value || null })
                        }
                    >
                        <option value="">Semua gedung</option>
                        {buildings.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </select>
                </div>
            )}
            <div className="grid gap-1.5">
                <Label htmlFor="rs-status">Status</Label>
                <select
                    id="rs-status"
                    className={selectClass}
                    value={filters.status}
                    onChange={(event) =>
                        visit({
                            status: event.target
                                .value as ResidentFilters['status'],
                        })
                    }
                >
                    <option value="semua">Tinggal & rencana</option>
                    <option value="aktif">Tinggal</option>
                    <option value="rencana">Rencana</option>
                </select>
            </div>
            <div className="grid gap-1.5">
                <Label htmlFor="rs-category">Kategori</Label>
                <select
                    id="rs-category"
                    className={selectClass}
                    value={filters.category ?? ''}
                    onChange={(event) =>
                        visit({ category: event.target.value || null })
                    }
                >
                    <option value="">Santri & pengawas</option>
                    {categories.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.label}
                        </option>
                    ))}
                </select>
            </div>
            <form
                className="grid gap-1.5"
                onSubmit={(event) => {
                    event.preventDefault();
                    visit({ q: q.trim() });
                }}
            >
                <Label htmlFor="rs-q">Cari nama</Label>
                <div className="relative">
                    <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        id="rs-q"
                        type="search"
                        className="pl-8"
                        placeholder="Nama penghuni, lalu Enter"
                        value={q}
                        maxLength={100}
                        onChange={(event) => setQ(event.target.value)}
                    />
                </div>
            </form>
        </div>
    );
}
