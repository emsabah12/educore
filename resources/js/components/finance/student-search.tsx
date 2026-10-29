import { Search } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/*
 * Cari siswa AKTIF di satu lembaga untuk form Keuangan (potongan FN-1b, tagihan sekali bayar FN-2b):
 * nama, awal NISN, atau awal nomor induk. Server memeriksa hak akses di lembaga itu.
 */

export type StudentHit = {
    enrollment_id: string;
    name: string;
    nisn: string | null;
    student_number: string | null;
    class: string | null;
    /** Pendaftaran masih terbuka (kasir juga menampilkan yang sudah keluar). */
    active?: boolean;
};

export function StudentSearch({
    id = 'student-search',
    url = '/keuangan/siswa/cari',
    organizationId,
    onSelect,
}: {
    id?: string;
    /** Endpoint JSON; kasir memakai `/keuangan/kasir/cari` (termasuk siswa yang sudah keluar). */
    url?: string;
    organizationId: string;
    onSelect: (student: StudentHit) => void;
}) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<StudentHit[] | null>(null);
    const [searching, setSearching] = useState(false);
    const [message, setMessage] = useState<string | null>(null);

    const search = async () => {
        setSearching(true);
        setMessage(null);

        try {
            const response = await fetch(
                `${url}?lembaga=${encodeURIComponent(organizationId)}&q=${encodeURIComponent(query)}`,
                {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                },
            );

            if (response.status === 429) {
                setResults(null);
                setMessage(
                    'Terlalu banyak pencarian. Tunggu sebentar lalu coba lagi.',
                );

                return;
            }

            const data = (await response.json()) as {
                message?: string;
                results?: StudentHit[];
            };

            if (!response.ok) {
                setResults(null);
                setMessage(data.message ?? 'Pencarian gagal. Coba lagi.');

                return;
            }

            setResults(data.results ?? []);
        } catch {
            setResults(null);
            setMessage(
                'Tidak bisa terhubung ke server. Periksa koneksi lalu coba lagi.',
            );
        } finally {
            setSearching(false);
        }
    };

    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>Siswa</Label>
            <div className="grid grid-cols-[minmax(0,1fr)_auto] gap-2">
                <Input
                    id={id}
                    value={query}
                    maxLength={100}
                    autoComplete="off"
                    placeholder="Nama, NISN, atau nomor induk"
                    onChange={(event) => setQuery(event.target.value)}
                    onKeyDown={(event) => {
                        // Enter mencari, bukan menyimpan form.
                        if (event.key === 'Enter') {
                            event.preventDefault();
                            void search();
                        }
                    }}
                />
                <Button
                    type="button"
                    variant="outline"
                    disabled={searching || query.trim() === ''}
                    onClick={() => void search()}
                >
                    <Search />
                    {searching ? 'Mencari…' : 'Cari'}
                </Button>
            </div>
            <InputError message={message ?? undefined} />

            {results !== null &&
                (results.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Tidak ada siswa yang cocok di lembaga ini.
                    </p>
                ) : (
                    <ul className="divide-y rounded-md border bg-background">
                        {results.map((hit) => (
                            <li key={hit.enrollment_id}>
                                <button
                                    type="button"
                                    onClick={() => onSelect(hit)}
                                    className="w-full px-3 py-2 text-left text-sm hover:bg-muted/50 focus-visible:bg-muted/50 focus-visible:outline-none"
                                >
                                    <span className="font-medium break-words">
                                        {hit.name}
                                    </span>
                                    <span className="block text-xs text-muted-foreground">
                                        {[
                                            hit.active === false
                                                ? 'Sudah keluar'
                                                : (hit.class ??
                                                  'Belum masuk kelas'),
                                            hit.student_number
                                                ? `NIS ${hit.student_number}`
                                                : null,
                                            hit.nisn
                                                ? `NISN ${hit.nisn}`
                                                : null,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ul>
                ))}
        </div>
    );
}
