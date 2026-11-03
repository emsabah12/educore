import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

type SavingsEntry = {
    id: string;
    slip_number: string;
    entry_date: string;
    kind: 'SETOR' | 'TARIK' | 'BAYAR_TAGIHAN';
    kind_label: string;
    method: string | null;
    amount: number;
    balance_after: number;
    taken_by: string | null;
    note: string | null;
};

type OrganizationSavings = {
    organization_id: string;
    organization: string;
    active: boolean;
    balance: number;
    entries: SavingsEntry[];
};

type Props = {
    student: { id: string; name: string };
    organizations: OrganizationSavings[];
    total: number;
    historyLimit: number;
};

const rupiah = new Intl.NumberFormat('id-ID');
const formatRupiah = (amount: number) => `Rp ${rupiah.format(amount)}`;

const dateFormat = new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium' });

function formatDate(value: string): string {
    const [year, month, day] = value.split('-').map(Number);

    return dateFormat.format(new Date(year, month - 1, day));
}

/**
 * Tabungan anak di portal orang tua/wali (PRD-005 §6, TB-4): saldo per lembaga dan transaksi sah terakhir; slip
 * bisa dibuka/cetak. Hanya baca; setor & tarik lewat kasir sekolah.
 */
export default function ChildSavings({
    student,
    organizations,
    total,
    historyLimit,
}: Props) {
    return (
        <>
            <Head title={`Tabungan — ${student.name}`} />

            <div className="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-4 p-4">
                <div>
                    <Button asChild variant="ghost" size="sm">
                        <Link href={`/anak-saya/${student.id}`}>
                            <ArrowLeft />
                            {student.name}
                        </Link>
                    </Button>
                </div>

                <Heading
                    title="Tabungan"
                    description="Saldo dan riwayat tabungan di sekolah. Setoran dan penarikan dilakukan melalui kasir sekolah; slip bisa dibuka dan dicetak di sini."
                />

                {organizations.length === 0 ? (
                    <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                        {student.name} belum punya tabungan di sekolah.
                    </p>
                ) : (
                    <>
                        {organizations.length > 1 && (
                            <div className="rounded-xl border p-4">
                                <p className="text-xs text-muted-foreground">
                                    Total saldo
                                </p>
                                <p className="text-2xl font-semibold tabular-nums">
                                    {formatRupiah(total)}
                                </p>
                            </div>
                        )}

                        {organizations.map((organization) => (
                            <section
                                key={organization.organization_id}
                                className="space-y-3 rounded-xl border p-4"
                            >
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <h2 className="min-w-0 font-semibold">
                                        {organization.organization}
                                        {!organization.active && (
                                            <Badge
                                                variant="outline"
                                                className="ml-2"
                                            >
                                                Sudah keluar
                                            </Badge>
                                        )}
                                    </h2>
                                    <p className="text-right">
                                        <span className="block text-xs text-muted-foreground">
                                            Saldo
                                        </span>
                                        <span className="text-xl font-semibold tabular-nums">
                                            {formatRupiah(organization.balance)}
                                        </span>
                                    </p>
                                </div>

                                {organization.entries.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        Belum ada transaksi.
                                    </p>
                                ) : (
                                    <ul className="divide-y rounded-lg border">
                                        {organization.entries.map((entry) => (
                                            <li
                                                key={entry.id}
                                                className="flex flex-wrap items-start justify-between gap-2 p-3 text-sm"
                                            >
                                                <div className="min-w-0 space-y-0.5">
                                                    <p className="font-medium">
                                                        {formatDate(
                                                            entry.entry_date,
                                                        )}{' '}
                                                        · {entry.kind_label}
                                                    </p>
                                                    <p className="text-muted-foreground">
                                                        {[
                                                            entry.method,
                                                            entry.taken_by &&
                                                                `diambil ${entry.taken_by}`,
                                                            entry.note,
                                                        ]
                                                            .filter(Boolean)
                                                            .join(' · ') || ' '}
                                                    </p>
                                                </div>
                                                <div className="text-right">
                                                    <p className="font-semibold tabular-nums">
                                                        {entry.kind === 'SETOR'
                                                            ? '+'
                                                            : '−'}
                                                        {formatRupiah(
                                                            entry.amount,
                                                        )}
                                                    </p>
                                                    <p className="text-xs text-muted-foreground tabular-nums">
                                                        Saldo{' '}
                                                        {formatRupiah(
                                                            entry.balance_after,
                                                        )}
                                                    </p>
                                                    <Link
                                                        href={`/anak-saya/${student.id}/slip-tabungan/${entry.id}`}
                                                        className="text-xs underline underline-offset-4"
                                                    >
                                                        Slip {entry.slip_number}
                                                    </Link>
                                                </div>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                                {organization.entries.length >=
                                    historyLimit && (
                                    <p className="text-xs text-muted-foreground">
                                        Menampilkan {historyLimit} transaksi
                                        terakhir.
                                    </p>
                                )}
                            </section>
                        ))}
                    </>
                )}
            </div>
        </>
    );
}
