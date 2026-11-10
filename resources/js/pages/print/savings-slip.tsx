import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Printer } from 'lucide-react';

type Slip = {
    id: string;
    tenant: string;
    organization: string;
    slip_number: string;
    entry_date: string;
    kind: 'SETOR' | 'TARIK' | 'BAYAR_TAGIHAN';
    kind_label: string;
    method: string | null;
    amount: number;
    amount_words: string;
    balance_after: number;
    taken_by: string | null;
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
};

type Props = {
    slip: Slip;
    amountLabel: string;
    balanceLabel: string;
    backUrl: string;
    /** Teks tautan kembali; bawaan untuk petugas (kasir). Portal orang tua: "Kembali ke tabungan". */
    backLabel?: string;
};

const longDate = new Intl.DateTimeFormat('id-ID', { dateStyle: 'long' });

function formatDate(value: string): string {
    const [year, month, day] = value.split('-').map(Number);

    return longDate.format(new Date(year, month - 1, day));
}

/**
 * Slip setoran / penarikan tabungan siswa (PRD-005 §4, TB-1): cetak / simpan PDF lewat dialog cetak browser.
 * Slip transaksi yang dibatalkan tetap bisa dibuka, dengan tanda BATAL.
 */
export default function PrintSavingsSlip({
    slip,
    amountLabel,
    balanceLabel,
    backUrl,
    backLabel = 'Kembali ke kasir',
}: Props) {
    const voided = slip.status === 'BATAL';
    const title =
        slip.kind === 'TARIK'
            ? 'SLIP PENARIKAN TABUNGAN'
            : slip.kind === 'SETOR'
              ? 'SLIP SETORAN TABUNGAN'
              : 'SLIP TABUNGAN';

    return (
        <>
            <Head title={`Slip ${slip.slip_number}`} />

            <div className="mx-auto max-w-2xl space-y-4 p-4 print:p-0">
                <div className="flex flex-wrap items-center justify-between gap-2 print:hidden">
                    <Link
                        href={backUrl}
                        className="inline-flex items-center gap-1 text-sm font-medium underline underline-offset-4"
                    >
                        <ArrowLeft className="size-4" />
                        {backLabel}
                    </Link>
                    <button
                        type="button"
                        onClick={() => window.print()}
                        className="inline-flex h-12 items-center gap-2 rounded-md bg-primary px-6 text-base font-medium text-primary-foreground hover:bg-primary-active"
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
                        <p className="text-base font-semibold">{slip.tenant}</p>
                        <p>{slip.organization}</p>
                        <p className="mt-2 text-lg font-bold tracking-wide">
                            {title}
                        </p>
                        <p className="font-mono">{slip.slip_number}</p>
                    </header>

                    <dl className="grid grid-cols-[9rem_1fr] gap-x-3 gap-y-1">
                        <dt>Tanggal</dt>
                        <dd>: {formatDate(slip.entry_date)}</dd>
                        <dt>Nama siswa</dt>
                        <dd>
                            : {slip.student.name}
                            {slip.student.class && ` (${slip.student.class})`}
                        </dd>
                        {slip.student.student_number && (
                            <>
                                <dt>NIS</dt>
                                <dd>: {slip.student.student_number}</dd>
                            </>
                        )}
                        <dt>Jenis</dt>
                        <dd>: {slip.kind_label}</dd>
                        {slip.method && (
                            <>
                                <dt>Cara</dt>
                                <dd>: {slip.method}</dd>
                            </>
                        )}
                        <dt>Jumlah</dt>
                        <dd className="font-semibold">: {amountLabel}</dd>
                        <dt>Saldo sesudahnya</dt>
                        <dd>: {balanceLabel}</dd>
                        {slip.taken_by && (
                            <>
                                <dt>Diambil oleh</dt>
                                <dd>: {slip.taken_by}</dd>
                            </>
                        )}
                        {slip.note && (
                            <>
                                <dt>Catatan</dt>
                                <dd>: {slip.note}</dd>
                            </>
                        )}
                    </dl>

                    <p>
                        Terbilang:{' '}
                        <span className="font-medium capitalize italic">
                            {slip.amount_words}
                        </span>
                    </p>

                    {voided && slip.void_reason && (
                        <p className="text-red-700">
                            Dibatalkan: {slip.void_reason}
                        </p>
                    )}

                    <div className="flex justify-between gap-6 pt-6">
                        <div className="w-56 text-center">
                            <p>
                                {slip.kind === 'TARIK'
                                    ? 'Penerima,'
                                    : 'Penyetor,'}
                            </p>
                            <div className="h-16" />
                            <p className="border-t pt-1">
                                {slip.taken_by ?? '..................'}
                            </p>
                        </div>
                        <div className="w-56 text-center">
                            <p>Petugas,</p>
                            <div className="h-16" />
                            <p className="border-t pt-1">
                                {slip.recorded_by ?? '..................'}
                            </p>
                        </div>
                    </div>
                </article>
            </div>
        </>
    );
}
