import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, ExternalLink } from 'lucide-react';
import {
    formatDate,
    formatNumber,
    formatRupiah,
} from '@/components/finance/ledger';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

type Props = {
    journal: {
        id: string;
        number: string;
        date: string;
        description: string;
        source: string;
        source_label: string;
        amount: number;
        status: 'SAH' | 'BATAL';
        void_reason: string | null;
    };
    lines: {
        account_code: string;
        account_name: string;
        organization: string;
        cash_account: string | null;
        debit: number;
        credit: number;
    }[];
    source_url: string | null;
};

/**
 * Rincian satu jurnal (PRD-007 §7, PB-1): baris debit/kredit per akun & lembaga, tautan ke transaksi sumbernya.
 */
export default function JournalShow({ journal, lines, source_url }: Props) {
    const debit = lines.reduce((sum, line) => sum + line.debit, 0);
    const credit = lines.reduce((sum, line) => sum + line.credit, 0);

    return (
        <>
            <Head title={`Jurnal ${journal.number}`} />

            <div className="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-4 p-4">
                <div>
                    <Button asChild variant="ghost" size="sm">
                        <Link href="/keuangan/pembukuan/jurnal">
                            <ArrowLeft />
                            Jurnal
                        </Link>
                    </Button>
                </div>

                <Heading
                    title={journal.number}
                    description={journal.description}
                />

                <section className="space-y-3 rounded-xl border p-4 text-sm">
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge
                            variant={
                                journal.status === 'SAH' ? 'default' : 'outline'
                            }
                        >
                            {journal.status === 'SAH' ? 'Sah' : 'Batal'}
                        </Badge>
                        <Badge variant="secondary">
                            {journal.source_label}
                        </Badge>
                    </div>
                    <dl className="grid grid-cols-[8rem_1fr] gap-x-3 gap-y-1">
                        <dt className="text-muted-foreground">Tanggal</dt>
                        <dd>{formatDate(journal.date)}</dd>
                        <dt className="text-muted-foreground">Jumlah</dt>
                        <dd className="font-semibold tabular-nums">
                            {formatRupiah(journal.amount)}
                        </dd>
                        {journal.void_reason && (
                            <>
                                <dt className="text-muted-foreground">
                                    Alasan batal
                                </dt>
                                <dd>{journal.void_reason}</dd>
                            </>
                        )}
                    </dl>
                    {journal.status === 'BATAL' && (
                        <p className="text-xs text-muted-foreground">
                            Jurnal batal tidak dihitung di buku besar dan
                            laporan.
                        </p>
                    )}
                    {source_url && (
                        <Button asChild variant="outline" size="sm">
                            <Link href={source_url}>
                                <ExternalLink />
                                Lihat transaksi sumber
                            </Link>
                        </Button>
                    )}
                </section>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full min-w-[36rem] text-sm">
                        <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                            <tr>
                                <th className="p-2 font-medium">Akun</th>
                                <th className="p-2 font-medium">Lembaga</th>
                                <th className="p-2 text-right font-medium">
                                    Debit
                                </th>
                                <th className="p-2 text-right font-medium">
                                    Kredit
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {lines.map((line, index) => (
                                <tr key={index}>
                                    <td
                                        className={`p-2 ${line.credit > 0 ? 'pl-8' : ''}`}
                                    >
                                        <span className="font-mono text-xs text-muted-foreground">
                                            {line.account_code}
                                        </span>{' '}
                                        {line.account_name}
                                        {line.cash_account && (
                                            <span className="block text-xs text-muted-foreground">
                                                Rekening: {line.cash_account}
                                            </span>
                                        )}
                                    </td>
                                    <td className="p-2">{line.organization}</td>
                                    <td className="p-2 text-right tabular-nums">
                                        {line.debit > 0
                                            ? formatNumber(line.debit)
                                            : ''}
                                    </td>
                                    <td className="p-2 text-right tabular-nums">
                                        {line.credit > 0
                                            ? formatNumber(line.credit)
                                            : ''}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot className="border-t bg-muted/30 font-medium">
                            <tr>
                                <td className="p-2" colSpan={2}>
                                    Total
                                </td>
                                <td className="p-2 text-right tabular-nums">
                                    {formatNumber(debit)}
                                </td>
                                <td className="p-2 text-right tabular-nums">
                                    {formatNumber(credit)}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </>
    );
}
