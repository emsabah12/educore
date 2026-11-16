import { Head } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { ResidentFilterBar } from '@/components/dormitory/resident-filters';
import { ResidentList } from '@/components/dormitory/residents';
import type {
    Option,
    PlacementPage,
    ResidentFilters,
} from '@/components/dormitory/types';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';

type Props = {
    filters: ResidentFilters;
    options: {
        dormitories: Option[];
        buildings: Option[];
        categories: Option[];
    };
    residents: PlacementPage;
};

/**
 * Daftar penghuni lintas asrama di cakupan pengguna (PRD-008 §16.5): yang sedang tinggal dan yang direncanakan.
 * Penempatan dilakukan dari halaman kamar; nama membuka riwayat hunian orang itu.
 */
export default function Residents({ filters, options, residents }: Props) {
    const filtered =
        filters.q !== '' ||
        filters.dormitory !== null ||
        filters.category !== null ||
        filters.status !== 'semua';

    return (
        <>
            <Head title="Penghuni asrama" />

            <div className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-4 p-4">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <Heading
                        title="Penghuni"
                        description="Santri dan pengawas yang sedang tinggal atau sudah direncanakan menempati kamar. Penempatan dilakukan dari halaman kamar."
                    />
                    {residents.total > 0 && (
                        <Button variant="outline" asChild className="shrink-0">
                            <a href={exportUrl(filters)}>
                                <Download />
                                Unduh .xlsx
                            </a>
                        </Button>
                    )}
                </div>

                <ResidentFilterBar
                    url="/asrama/penghuni"
                    filters={filters}
                    dormitories={options.dormitories}
                    buildings={options.buildings}
                    categories={options.categories}
                />

                <ResidentList
                    page={residents}
                    showDormitory
                    emptyText={
                        filtered
                            ? 'Tidak ada penghuni yang cocok dengan saringan.'
                            : options.dormitories.length === 0
                              ? 'Belum ada asrama di cakupan Anda.'
                              : 'Belum ada penghuni. Buka kamar di menu Asrama, lalu pilih Tempatkan.'
                    }
                />
            </div>
        </>
    );
}

/** Unduhan .xlsx mengikuti saringan yang sedang dipakai (kunci alamat sama dengan halaman ini). */
function exportUrl(filters: ResidentFilters): string {
    const params = new URLSearchParams();

    if (filters.dormitory) {
        params.set('asrama', filters.dormitory);
    }

    if (filters.building) {
        params.set('gedung', filters.building);
    }

    if (filters.status !== 'semua') {
        params.set('status', filters.status);
    }

    if (filters.category) {
        params.set('kategori', filters.category);
    }

    if (filters.q) {
        params.set('q', filters.q);
    }

    if (filters.review) {
        params.set('tinjau', '1');
    }

    const query = params.toString();

    return `/asrama/penghuni/unduh${query ? `?${query}` : ''}`;
}
