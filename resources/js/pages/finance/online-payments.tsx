import { Head, Link, router } from '@inertiajs/react';
import { CircleAlert } from 'lucide-react';
import { formatDateTime } from '@/components/finance/online-order';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

type Option = { value: string; label: string };

type StaffRow = {
    id: string;
    organization_id: string;
    organization: string;
    order_id: string;
    student: string;
    class: string | null;
    method_label: string;
    gross_amount: number;
    invoice_amount: number;
    status: 'MENUNGGU' | 'LUNAS' | 'KEDALUWARSA' | 'GAGAL' | 'DIBATALKAN';
    status_label: string;
    created_at: string;
    paid_at: string | null;
    overpaid_amount: number;
    overpaid_settled: boolean;
};

type Props = {
    organizations: Option[];
    organizationId: string | null;
    statusOptions: Option[];
    status: string | null;
    months: Option[];
    month: string | null;
    orders: {
        rows: StaffRow[];
        total: number;
        page: number;
        last_page: number;
    };
    overpaid: { count: number; amount: number };
};

const rupiah = new Intl.NumberFormat('id-ID');
const formatRupiah = (amount: number) => `Rp ${rupiah.format(amount)}`;

const selectClass =
    'h-9 w-full min-w-0 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';

/**
 * Pembayaran online untuk petugas (PRD-006 §9, MG-3): pesanan bayar online orang tua per lembaga dalam cakupan,
 * saring status / kelebihan bayar / bulan; rincian per pesanan. Hanya baca di sini.
 */
export default function OnlinePayments({
    organizations,
    organizationId,
    statusOptions,
    status,
    months,
    month,
    orders,
    overpaid,
}: Props) {
    const visit = (overrides: Record<string, string | null>) => {
        const merged: Record<string, string | null> = {
            lembaga: organizationId,
            status,
            bulan: month,
            ...overrides,
        };
        const clean: Record<string, string> = {};

        for (const [key, value] of Object.entries(merged)) {
            if (value) {
                clean[key] = value;
            }
        }

        router.get('/keuangan/pembayaran-online', clean, {
            preserveScroll: true,
        });
    };

    return (
        <>
            <Head title="Pembayaran online" />

            <div className="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-4 p-4">
                <Heading
                    title="Pembayaran online"
                    description="Pesanan bayar online orang tua lewat Midtrans (VA / QRIS). Kuitansi dibuat otomatis saat pembayaran diterima."
                />

                {overpaid.count > 0 && status !== 'KELEBIHAN' && (
                    <button
                        type="button"
                        onClick={() =>
                            visit({ status: 'KELEBIHAN', halaman: null })
                        }
                        className="flex items-start gap-2 rounded-xl border border-amber-300 bg-amber-50 p-3 text-left text-sm text-amber-950 hover:bg-amber-100 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100"
                    >
                        <CircleAlert className="mt-0.5 size-4 shrink-0" />
                        <span>
                            {overpaid.count} pembayaran online punya kelebihan
                            bayar {formatRupiah(overpaid.amount)} yang belum
                            dikembalikan. Klik untuk melihat.
                        </span>
                    </button>
                )}

                <div className="grid gap-3 sm:grid-cols-3">
                    <SelectField
                        id="op-organization"
                        label="Lembaga"
                        value={organizationId ?? ''}
                        options={[
                            ...(organizations.length > 1
                                ? [{ value: '', label: 'Semua lembaga' }]
                                : []),
                            ...organizations,
                        ]}
                        onChange={(value) =>
                            visit({ lembaga: value || null, halaman: null })
                        }
                    />
                    <SelectField
                        id="op-status"
                        label="Status"
                        value={status ?? ''}
                        options={[
                            { value: '', label: 'Semua status' },
                            ...statusOptions,
                        ]}
                        onChange={(value) =>
                            visit({ status: value || null, halaman: null })
                        }
                    />
                    <SelectField
                        id="op-month"
                        label="Bulan dibuat"
                        value={month ?? ''}
                        options={[
                            { value: '', label: 'Semua bulan' },
                            ...months,
                        ]}
                        onChange={(value) =>
                            visit({ bulan: value || null, halaman: null })
                        }
                    />
                </div>

                <p className="text-sm text-muted-foreground">
                    {orders.total} pesanan
                </p>

                {orders.rows.length === 0 ? (
                    <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                        Belum ada pembayaran online yang cocok dengan saringan.
                    </p>
                ) : (
                    <ul className="divide-y rounded-xl border">
                        {orders.rows.map((row) => (
                            <li key={row.id}>
                                <Link
                                    href={`/keuangan/pembayaran-online/${row.id}`}
                                    className="flex flex-wrap items-start justify-between gap-3 p-4 text-sm hover:bg-muted/40"
                                >
                                    <div className="min-w-0 space-y-0.5">
                                        <p className="font-medium">
                                            {row.student}
                                            {row.class && (
                                                <span className="font-normal text-muted-foreground">
                                                    {' '}
                                                    · {row.class}
                                                </span>
                                            )}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {[
                                                row.organization,
                                                formatDateTime(row.created_at),
                                                row.method_label,
                                            ].join(' · ')}
                                        </p>
                                        <p className="font-mono text-xs text-muted-foreground">
                                            {row.order_id}
                                        </p>
                                    </div>
                                    <div className="flex flex-col items-end gap-1">
                                        <span className="font-semibold tabular-nums">
                                            {formatRupiah(row.gross_amount)}
                                        </span>
                                        <Badge
                                            variant={
                                                row.status === 'LUNAS'
                                                    ? 'default'
                                                    : row.status === 'MENUNGGU'
                                                      ? 'secondary'
                                                      : 'outline'
                                            }
                                        >
                                            {row.status_label}
                                        </Badge>
                                        {row.overpaid_amount > 0 && (
                                            <Badge
                                                variant={
                                                    row.overpaid_settled
                                                        ? 'outline'
                                                        : 'destructive'
                                                }
                                            >
                                                {row.overpaid_settled
                                                    ? 'Kelebihan sudah dikembalikan'
                                                    : `Kelebihan ${formatRupiah(row.overpaid_amount)}`}
                                            </Badge>
                                        )}
                                    </div>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}

                {orders.last_page > 1 && (
                    <nav
                        aria-label="Halaman"
                        className="flex flex-wrap items-center justify-between gap-2 text-sm"
                    >
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            disabled={orders.page <= 1}
                            onClick={() =>
                                visit({ halaman: String(orders.page - 1) })
                            }
                        >
                            Sebelumnya
                        </Button>
                        <span className="text-muted-foreground">
                            Halaman {orders.page} dari {orders.last_page}
                        </span>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            disabled={orders.page >= orders.last_page}
                            onClick={() =>
                                visit({ halaman: String(orders.page + 1) })
                            }
                        >
                            Berikutnya
                        </Button>
                    </nav>
                )}
            </div>
        </>
    );
}

function SelectField({
    id,
    label,
    value,
    options,
    onChange,
}: {
    id: string;
    label: string;
    value: string;
    options: Option[];
    onChange: (value: string) => void;
}) {
    return (
        <div className="grid min-w-0 gap-1.5">
            <Label htmlFor={id}>{label}</Label>
            <select
                id={id}
                className={selectClass}
                value={value}
                onChange={(event) => onChange(event.target.value)}
            >
                {options.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
        </div>
    );
}
