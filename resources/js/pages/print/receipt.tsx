import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Printer } from 'lucide-react';

type Receipt = {
    id: string;
    tenant: string;
    organization: string;
    receipt_number: string;
    paid_on: string;
    method: string;
    amount: number;
    amount_words: string;
    note: string | null;
    status: 'SAH' | 'BATAL';
    void_reason: string | null;
    recorded_by: string | null;
    student: {
        name: string;
        class: string | null;
        student_number: string | null;
        nisn: string | null;
    };
    items: { description: string; amount: number }[];
};

type Props = {
    receipt: Receipt;
    amountLabel: string;
    backUrl: string;
};

const rupiah = new Intl.NumberFormat('id-ID');
const longDate = new Intl.DateTimeFormat('id-ID', { dateStyle: 'long' });

function formatDate(value: string): string {
    const [year, month, day] = value.split('-').map(Number);

    return longDate.format(new Date(year, month - 1, day));
}

/**
 * Kuitansi pembayaran (PRD-003 §4, FN-3): satu halaman, cetak / simpan PDF lewat dialog cetak browser.
 * Kuitansi yang dibatalkan tetap bisa dibuka, dengan tanda BATAL.
 */
export default function PrintReceipt({ receipt, amountLabel, backUrl }: Props) {
    const voided = receipt.status === 'BATAL';

    return (
        <>
            <Head title={`Kuitansi ${receipt.receipt_number}`} />

            <div className="mx-auto max-w-2xl space-y-4 p-4 print:p-0">
                <div className="flex flex-wrap items-center justify-between gap-2 print:hidden">
                    <Link
                        href={backUrl}
                        className="inline-flex items-center gap-1 text-sm font-medium underline underline-offset-4"
                    >
                        <ArrowLeft className="size-4" />
                        Kembali ke kasir
                    </Link>
                    <button
                        type="button"
                        onClick={() => window.print()}
                        className="inline-flex h-9 items-center gap-2 rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground"
                    >
                        <Printer className="size-4" />
                        Cetak / simpan PDF
                    </button>
                </div>

                <article className="relative space-y-4 rounded-lg border bg-white p-6 text-sm text-black print:rounded-none print:border-0">
                    {voided && (
                        <div
                            aria-hidden
                            className="pointer-events-none absolute inset-0 flex items-center justify-center"
                        >
                            <span className="-rotate-12 rounded border-4 border-red-600 px-6 py-2 text-5xl font-bold tracking-widest text-red-600/70">
                                BATAL
                            </span>
                        </div>
                    )}

                    <header className="border-b pb-3 text-center">
                        <p className="text-base font-semibold">
                            {receipt.tenant}
                        </p>
                        <p>{receipt.organization}</p>
                        <p className="mt-2 text-lg font-bold tracking-wide">
                            KUITANSI
                        </p>
                        <p className="font-mono">{receipt.receipt_number}</p>
                    </header>

                    <dl className="grid grid-cols-[9rem_1fr] gap-x-3 gap-y-1">
                        <dt>Tanggal</dt>
                        <dd>: {formatDate(receipt.paid_on)}</dd>
                        <dt>Diterima dari</dt>
                        <dd>
                            : {receipt.student.name}
                            {receipt.student.class &&
                                ` (${receipt.student.class})`}
                        </dd>
                        {receipt.student.student_number && (
                            <>
                                <dt>NIS</dt>
                                <dd>: {receipt.student.student_number}</dd>
                            </>
                        )}
                        <dt>Cara bayar</dt>
                        <dd>: {receipt.method}</dd>
                        {receipt.note && (
                            <>
                                <dt>Catatan</dt>
                                <dd>: {receipt.note}</dd>
                            </>
                        )}
                    </dl>

                    <table className="w-full border-collapse">
                        <thead>
                            <tr className="border-y">
                                <th className="py-1 text-left font-medium">
                                    Untuk pembayaran
                                </th>
                                <th className="py-1 text-right font-medium">
                                    Jumlah (Rp)
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {receipt.items.map((item, index) => (
                                <tr key={index} className="border-b">
                                    <td className="py-1">{item.description}</td>
                                    <td className="py-1 text-right tabular-nums">
                                        {rupiah.format(item.amount)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot>
                            <tr>
                                <td className="py-1 font-semibold">Total</td>
                                <td className="py-1 text-right font-semibold tabular-nums">
                                    {rupiah.format(receipt.amount)}
                                </td>
                            </tr>
                        </tfoot>
                    </table>

                    <p>
                        Terbilang:{' '}
                        <span className="font-medium capitalize italic">
                            {receipt.amount_words}
                        </span>{' '}
                        ({amountLabel})
                    </p>

                    {voided && receipt.void_reason && (
                        <p className="text-red-700">
                            Dibatalkan: {receipt.void_reason}
                        </p>
                    )}

                    <div className="flex justify-end pt-6">
                        <div className="w-56 text-center">
                            <p>Petugas,</p>
                            <div className="h-16" />
                            <p className="border-t pt-1">
                                {receipt.recorded_by ?? '..................'}
                            </p>
                        </div>
                    </div>
                </article>
            </div>
        </>
    );
}
