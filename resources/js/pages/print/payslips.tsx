import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Printer } from 'lucide-react';

type Line = {
    group: string;
    label: string;
    detail: string | null;
    kind: 'PENDAPATAN' | 'POTONGAN';
    amount: number;
};

type Slip = {
    employee_id: string;
    name: string;
    employee_number: string | null;
    organization: string | null;
    lines: Line[];
    basis: {
        present_days: number;
        absent: number;
        late: number;
        early: number;
        empty: number;
    };
    earning: number;
    deduction: number;
    net: number;
};

type Props = {
    tenant: string;
    month: string;
    label: string;
    lock: { locked_at: string; until: string } | null;
    slips: Slip[];
    backUrl: string;
    backLabel: string;
};

const rupiah = new Intl.NumberFormat('id-ID');

function money(value: number): string {
    return `Rp ${rupiah.format(value)}`;
}

/**
 * Slip gaji (PRD-001 §16.3, HR-5c-2): satu halaman per pegawai, dari snapshot bulan terkunci. Cetak atau
 * simpan PDF lewat dialog cetak browser.
 */
export default function PrintPayslips({
    tenant,
    label,
    lock,
    slips,
    backUrl,
    backLabel,
}: Props) {
    return (
        <>
            <Head title={`Slip gaji ${label}`} />
            <style>{'@page { size: A4; margin: 15mm; }'}</style>

            <div className="mx-auto max-w-[210mm] space-y-4 p-4 print:max-w-none print:space-y-0 print:p-0">
                <div className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-gray-300 p-4 text-sm print:hidden">
                    <Link
                        href={backUrl}
                        className="inline-flex items-center gap-1 text-gray-600 hover:text-black"
                    >
                        <ArrowLeft className="size-4" />
                        {backLabel}
                    </Link>
                    <button
                        type="button"
                        onClick={() => window.print()}
                        className="inline-flex items-center gap-2 rounded-md bg-black px-4 py-2 font-medium text-white hover:bg-gray-800"
                    >
                        <Printer className="size-4" />
                        Cetak / simpan PDF ({slips.length} slip)
                    </button>
                </div>

                {slips.length === 0 && (
                    <p className="rounded-lg border border-dashed border-gray-300 p-6 text-center text-sm text-gray-600">
                        Tidak ada slip untuk pilihan ini.
                    </p>
                )}

                {slips.map((slip) => {
                    const earnings = slip.lines.filter(
                        (line) => line.kind === 'PENDAPATAN',
                    );
                    const deductions = slip.lines.filter(
                        (line) => line.kind === 'POTONGAN',
                    );

                    return (
                        <article
                            key={slip.employee_id}
                            className="break-after-page space-y-4 border border-gray-300 bg-white p-6 text-[10.5pt] leading-snug print:border-0 print:p-0"
                        >
                            <header className="space-y-0.5 border-b-2 border-black pb-2 text-center">
                                <p className="font-semibold">{tenant}</p>
                                <p className="text-base font-bold uppercase">
                                    Slip Gaji
                                </p>
                                <p>Bulan {label}</p>
                            </header>

                            <table className="w-full text-left">
                                <tbody>
                                    <Identity label="Nama" value={slip.name} />
                                    <Identity
                                        label="Nomor induk"
                                        value={slip.employee_number ?? '—'}
                                    />
                                    <Identity
                                        label="Lembaga"
                                        value={slip.organization ?? '—'}
                                    />
                                </tbody>
                            </table>

                            <Section title="Pendapatan" lines={earnings} />
                            <Section title="Potongan" lines={deductions} />

                            <table className="w-full border-collapse text-left">
                                <tbody>
                                    <TotalRow
                                        label="Total pendapatan"
                                        value={slip.earning}
                                    />
                                    <TotalRow
                                        label="Total potongan"
                                        value={slip.deduction}
                                    />
                                    <tr className="border-t-2 border-black text-[12pt] font-bold">
                                        <td className="py-1.5">Gaji bersih</td>
                                        <td className="py-1.5 text-right tabular-nums">
                                            {money(slip.net)}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>

                            <p className="text-[9pt] text-gray-700">
                                Presensi {label}
                                {lock ? ` (s.d. ${lock.until})` : ''}: hadir{' '}
                                {slip.basis.present_days} hari, alpa{' '}
                                {slip.basis.absent}, terlambat {slip.basis.late}
                                , pulang cepat {slip.basis.early}.
                            </p>
                            <p className="text-[9pt] text-gray-500">
                                {lock
                                    ? `Rekap dikunci ${lock.locked_at}. `
                                    : ''}
                                Dibuat oleh EduCore.
                            </p>
                        </article>
                    );
                })}
            </div>
        </>
    );
}

function Identity({ label, value }: { label: string; value: string }) {
    return (
        <tr>
            <td className="w-32 py-0.5 align-top text-gray-700">{label}</td>
            <td className="py-0.5 align-top">: {value}</td>
        </tr>
    );
}

function Section({ title, lines }: { title: string; lines: Line[] }) {
    return (
        <table className="w-full border-collapse text-left">
            <thead>
                <tr className="bg-gray-100">
                    <th className="border border-gray-400 px-2 py-1 font-semibold">
                        {title}
                    </th>
                    <th className="w-36 border border-gray-400 px-2 py-1 text-right font-semibold">
                        Nominal
                    </th>
                </tr>
            </thead>
            <tbody>
                {lines.length === 0 ? (
                    <tr>
                        <td
                            className="border border-gray-400 px-2 py-1 text-gray-600"
                            colSpan={2}
                        >
                            —
                        </td>
                    </tr>
                ) : (
                    lines.map((line, index) => (
                        <tr key={index}>
                            <td className="border border-gray-400 px-2 py-1 align-top">
                                {line.label}
                                {line.detail && (
                                    <span className="block text-[9pt] text-gray-600">
                                        {line.detail}
                                    </span>
                                )}
                            </td>
                            <td className="border border-gray-400 px-2 py-1 text-right align-top tabular-nums">
                                {money(line.amount)}
                            </td>
                        </tr>
                    ))
                )}
            </tbody>
        </table>
    );
}

function TotalRow({ label, value }: { label: string; value: number }) {
    return (
        <tr>
            <td className="py-0.5">{label}</td>
            <td className="py-0.5 text-right tabular-nums">{money(value)}</td>
        </tr>
    );
}
