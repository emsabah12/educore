import { Search } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/*
 * Cari orang tua/wali yang sudah tercatat (PRD-002 §4.5, revisi 2026-10-28): sebagian nama atau
 * nomor HP. Hasil: nama, HP tersamar, nama anak + lembaganya. Pilihan tetap diperiksa ulang server.
 */

export type GuardianHit = {
    membership_id: string;
    name: string;
    /** HP tersamar, mis. "0813••••2222". */
    phone: string | null;
    children: { name: string; organization: string | null }[];
    more_children: number;
    already_linked: boolean;
};

export function GuardianSearch({
    id,
    url,
    selected,
    onSelect,
    error,
}: {
    id: string;
    /** Endpoint JSON, mis. "/orang-tua/cari" atau "/siswa/{id}/orang-tua/cari". */
    url: string;
    selected: GuardianHit | null;
    onSelect: (hit: GuardianHit) => void;
    error?: string;
}) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<GuardianHit[] | null>(null);
    const [searching, setSearching] = useState(false);
    const [message, setMessage] = useState<string | null>(null);

    const search = async () => {
        setSearching(true);
        setMessage(null);

        try {
            const response = await fetch(
                `${url}?q=${encodeURIComponent(query)}`,
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
                results?: GuardianHit[];
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
        <div className="grid gap-3">
            <div className="grid gap-2">
                <Label htmlFor={id}>Nama atau nomor HP orang tua/wali</Label>
                <div className="grid grid-cols-[1fr_auto] gap-2">
                    <Input
                        id={id}
                        value={query}
                        maxLength={100}
                        autoComplete="off"
                        placeholder="mis. Siti atau 0813"
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
            </div>

            {results !== null &&
                (results.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Tidak ada orang tua/wali tercatat yang cocok. Isi
                        sebagai orang baru.
                    </p>
                ) : (
                    <fieldset className="space-y-2">
                        <legend className="text-sm font-medium">
                            Pilih orangnya
                        </legend>
                        {results.map((hit) => (
                            <label
                                key={hit.membership_id}
                                className="flex items-start gap-2 rounded-md border p-3 text-sm has-[:disabled]:opacity-60"
                            >
                                <input
                                    type="radio"
                                    name={`${id}-choice`}
                                    value={hit.membership_id}
                                    checked={
                                        selected?.membership_id ===
                                        hit.membership_id
                                    }
                                    disabled={hit.already_linked}
                                    onChange={() => onSelect(hit)}
                                    className="mt-0.5"
                                />
                                <GuardianHitText hit={hit} />
                            </label>
                        ))}
                        {results.length >= 10 && (
                            <p className="text-xs text-muted-foreground">
                                Hanya 10 hasil pertama. Perjelas kata kuncinya
                                bila orangnya belum tampil.
                            </p>
                        )}
                    </fieldset>
                ))}
            <InputError message={error} />
        </div>
    );
}

export function GuardianHitText({ hit }: { hit: GuardianHit }) {
    const children = hit.children
        .map((child) =>
            child.organization
                ? `${child.name} (${child.organization})`
                : child.name,
        )
        .join(', ');

    return (
        <span className="min-w-0 break-words">
            <span className="font-medium">{hit.name}</span>
            {hit.phone && (
                <span className="ml-2 font-mono text-muted-foreground">
                    {hit.phone}
                </span>
            )}
            <span className="block text-muted-foreground">
                {hit.already_linked
                    ? 'Sudah tertaut ke siswa ini'
                    : `Anak: ${children}${hit.more_children > 0 ? `, +${hit.more_children} lainnya` : ''}`}
            </span>
        </span>
    );
}
