import { Head, Link } from '@inertiajs/react';
import { CircleAlert, CircleCheck } from 'lucide-react';
import { formatRupiah } from '@/components/finance/ledger';
import type { LedgerSettings } from '@/components/finance/ledger';
import { ledgerNotice } from '@/components/finance/ledger-filters';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

type Row = {
    kind: 'KAS' | 'PIUTANG' | 'TITIPAN' | 'RAK';
    label: string;
    organization: string;
    ledger: number;
    book: number;
    difference: number;
    href: string | null;
};

type Props = {
    settings: LedgerSettings & { pending: number };
    canManage: boolean;
    rows: Row[];
    mismatches: number;
};

const KINDS: Record<
    Row['kind'],
    { title: string; book: string; hint: string }
> = {
    KAS: {
        title: 'Kas & bank per rekening',
        book: 'Buku kas',
        hint: 'Biasanya karena jurnal belum disusun ulang sesudah saldo awal rekening diubah. Susun ulang jurnal di Pengaturan pembukuan → Periode.',
    },
    PIUTANG: {
        title: 'Piutang siswa per lembaga',
        book: 'Sisa tagihan',
        hint: 'Ada kuitansi yang belum tercermin di buku kas (mis. halaman Kas lembaga belum pernah dibuka, atau tanggal bayar sebelum tanggal saldo awal rekening). Buka halaman Kas lembaga itu, lalu susun ulang jurnal.',
    },
    TITIPAN: {
        title: 'Titipan tabungan per lembaga',
        book: 'Saldo tabungan',
        hint: 'Transaksi tabungan belum tercermin di buku kas Titipan. Buka halaman Kas lembaga itu, lalu susun ulang jurnal.',
    },
    RAK: {
        title: 'Rekening antar-kantor',
        book: 'Harus',
        hint: 'Mutasi antar-kas tidak seimbang. Susun ulang jurnal.',
    },
};

/**
 * Cek kecocokan (PRD-007 §7, PB-2b): saldo pembukuan dibandingkan dengan buku pembantu — buku kas, sisa tagihan,
 * saldo tabungan, RAK gabungan = 0. Semua tanggal.
 */
export default function LedgerReconciliation({
    settings,
    canManage,
    rows,
    mismatches,
}: Props) {
    const notice = ledgerNotice(settings);
    const kinds = (Object.keys(KINDS) as Row['kind'][]).filter((kind) =>
        rows.some((row) => row.kind === kind),
    );

    return (
        <>
            <Head title="Cek kecocokan" />

            <div className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-4 p-4">
                <Heading
                    title="Cek kecocokan"
                    description="Memastikan saldo di pembukuan sama dengan buku kas, sisa tagihan siswa, dan saldo tabungan."
                />

                {!settings.active ? (
                    <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                        Pembukuan belum aktif.
                    </p>
                ) : (
                    <>
                        {notice && (
                            <p className="flex items-start gap-2 rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100">
                                <CircleAlert className="mt-0.5 size-4 shrink-0" />
                                {notice}
                            </p>
                        )}

                        {mismatches === 0 ? (
                            <p className="flex items-start gap-2 rounded-xl border border-emerald-300 bg-emerald-50 p-3 text-sm text-emerald-950 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-100">
                                <CircleCheck className="mt-0.5 size-4 shrink-0" />
                                Semua cocok.
                            </p>
                        ) : (
                            <div className="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100">
                                <span className="flex items-start gap-2">
                                    <CircleAlert className="mt-0.5 size-4 shrink-0" />
                                    {mismatches} saldo tidak cocok. Lihat
                                    petunjuk di tiap bagian.
                                </span>
                                {canManage && (
                                    <Button asChild size="sm" variant="outline">
                                        <Link href="/keuangan/pembukuan/pengaturan?tab=periode">
                                            Susun ulang jurnal
                                        </Link>
                                    </Button>
                                )}
                            </div>
                        )}

                        {rows.length === 0 && (
                            <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                                Belum ada saldo untuk dicek.
                            </p>
                        )}

                        {kinds.map((kind) => {
                            const items = rows.filter(
                                (row) => row.kind === kind,
                            );
                            const broken = items.some(
                                (row) => row.difference !== 0,
                            );

                            return (
                                <section key={kind} className="space-y-2">
                                    <h2 className="text-sm font-medium">
                                        {KINDS[kind].title}
                                    </h2>
                                    {broken && (
                                        <p className="text-xs text-muted-foreground">
                                            {KINDS[kind].hint}
                                        </p>
                                    )}
                                    <div className="overflow-x-auto rounded-xl border">
                                        <table className="w-full min-w-[36rem] text-sm">
                                            <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                                                <tr>
                                                    <th className="p-2 font-medium">
                                                        Bagian
                                                    </th>
                                                    <th className="p-2 text-right font-medium">
                                                        Pembukuan
                                                    </th>
                                                    <th className="p-2 text-right font-medium">
                                                        {KINDS[kind].book}
                                                    </th>
                                                    <th className="p-2 text-right font-medium">
                                                        Selisih
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y">
                                                {items.map((row, index) => (
                                                    <tr
                                                        key={`${row.organization}-${row.label}-${index}`}
                                                    >
                                                        <td className="p-2">
                                                            {row.href ? (
                                                                <Link
                                                                    href={
                                                                        row.href
                                                                    }
                                                                    className="font-medium underline-offset-4 hover:underline"
                                                                >
                                                                    {kind ===
                                                                    'KAS'
                                                                        ? row.label
                                                                        : row.organization}
                                                                </Link>
                                                            ) : (
                                                                <span className="font-medium">
                                                                    {kind ===
                                                                    'KAS'
                                                                        ? row.label
                                                                        : row.organization}
                                                                </span>
                                                            )}
                                                            {kind === 'KAS' && (
                                                                <span className="block text-xs text-muted-foreground">
                                                                    {
                                                                        row.organization
                                                                    }
                                                                </span>
                                                            )}
                                                        </td>
                                                        <td className="p-2 text-right tabular-nums">
                                                            {formatRupiah(
                                                                row.ledger,
                                                            )}
                                                        </td>
                                                        <td className="p-2 text-right tabular-nums">
                                                            {formatRupiah(
                                                                row.book,
                                                            )}
                                                        </td>
                                                        <td className="p-2 text-right">
                                                            {row.difference ===
                                                            0 ? (
                                                                <Badge variant="secondary">
                                                                    Cocok
                                                                </Badge>
                                                            ) : (
                                                                <Badge variant="destructive">
                                                                    {formatRupiah(
                                                                        row.difference,
                                                                    )}
                                                                </Badge>
                                                            )}
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                </section>
                            );
                        })}
                    </>
                )}
            </div>
        </>
    );
}
