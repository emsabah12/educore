import { router } from '@inertiajs/react';
import { useState } from 'react';
import { formatDate, selectClass } from '@/components/finance/ledger';
import type {
    LedgerPeriod,
    Option,
    PeriodPreset,
} from '@/components/finance/ledger';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/**
 * Saringan laporan pembukuan (PRD-007 §7, PB-2b): lingkup, rentang tanggal (satu tahun buku), pilihan cepat.
 * `extra` = parameter lain yang ikut dikirim (mis. akun buku besar).
 */
export function LedgerFilters({
    url,
    scopes,
    scope,
    period,
    presets,
    extra = {},
}: {
    url: string;
    scopes: Option[];
    scope: string | null;
    period: LedgerPeriod;
    presets: PeriodPreset[];
    extra?: Record<string, string | null>;
}) {
    const [from, setFrom] = useState(period.from);
    const [to, setTo] = useState(period.to);

    const visit = (overrides: Record<string, string | null>) => {
        const merged: Record<string, string | null> = {
            ...extra,
            lingkup: scope,
            dari: from,
            sampai: to,
            ...overrides,
        };
        const clean: Record<string, string> = {};

        for (const [key, value] of Object.entries(merged)) {
            if (value) {
                clean[key] = value;
            }
        }

        router.get(url, clean, { preserveScroll: true });
    };

    return (
        <div className="space-y-3">
            <div className="grid gap-3 sm:grid-cols-[minmax(0,1fr)_10rem_10rem_auto] sm:items-end">
                <div className="grid min-w-0 gap-1.5">
                    <Label htmlFor="lf-scope">Lingkup</Label>
                    <select
                        id="lf-scope"
                        className={selectClass}
                        value={scope ?? ''}
                        onChange={(event) =>
                            visit({
                                lingkup: event.target.value,
                                halaman: null,
                            })
                        }
                    >
                        {scopes.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </select>
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor="lf-from">Dari</Label>
                    <Input
                        id="lf-from"
                        type="date"
                        value={from}
                        min={period.start_date}
                        onChange={(event) => setFrom(event.target.value)}
                    />
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor="lf-to">Sampai</Label>
                    <Input
                        id="lf-to"
                        type="date"
                        value={to}
                        min={from}
                        onChange={(event) => setTo(event.target.value)}
                    />
                </div>
                <Button
                    type="button"
                    variant="outline"
                    onClick={() => visit({ halaman: null })}
                >
                    Tampilkan
                </Button>
            </div>
            <div className="flex flex-wrap gap-2">
                {presets.map((preset) => (
                    <Button
                        key={preset.label}
                        type="button"
                        size="sm"
                        variant={
                            preset.from === period.from &&
                            preset.to === period.to
                                ? 'secondary'
                                : 'ghost'
                        }
                        onClick={() =>
                            visit({
                                dari: preset.from,
                                sampai: preset.to,
                                halaman: null,
                            })
                        }
                    >
                        {preset.label}
                    </Button>
                ))}
            </div>
            <p className="text-xs text-muted-foreground">
                {formatDate(period.from)} s.d. {formatDate(period.to)} · tahun
                buku {formatDate(period.fiscal_start)} –{' '}
                {formatDate(period.fiscal_end)}
                {period.clamped &&
                    '. Rentang dibatasi sampai akhir tahun buku; pilih tahun buku berikutnya untuk tanggal sesudahnya.'}
            </p>
        </div>
    );
}

/** Pemberitahuan pembukuan belum aktif / perlu disusun ulang / antrean (dipakai halaman laporan). */
export function ledgerNotice(settings: {
    rebuilding: boolean;
    needs_rebuild: boolean;
    pending: number;
}): string | null {
    if (settings.rebuilding) {
        return 'Jurnal sedang disusun ulang; angka di halaman ini belum final.';
    }

    if (settings.needs_rebuild) {
        return 'Pengaturan pembukuan berubah dan jurnal lama belum disusun ulang; angka bisa belum sesuai.';
    }

    if (settings.pending > 0) {
        return `${settings.pending} transaksi masih menunggu dijurnal. Muat ulang halaman sebentar lagi.`;
    }

    return null;
}
