/** Pesanan bayar online (PRD-006 MG-2), bentuk data dari server (OnlinePaymentList). */
export type OnlineOrder = {
    id: string;
    enrollment_id: string;
    order_id: string;
    method: string;
    method_label: string;
    invoice_amount: number;
    fee_amount: number;
    gross_amount: number;
    status: 'MENUNGGU' | 'LUNAS' | 'KEDALUWARSA' | 'GAGAL' | 'DIBATALKAN';
    status_label: string;
    expires_at: string;
    paid_at: string | null;
    payment_id: string | null;
    overpaid_amount: number;
    overpaid_settled_at?: string | null;
    organization_id?: string;
    created_at: string;
    invoices: {
        id: string;
        description: string;
        due_date: string;
        outstanding: number;
    }[];
};

const dateTime = new Intl.DateTimeFormat('id-ID', {
    dateStyle: 'medium',
    timeStyle: 'short',
});

/** Waktu dari server (ISO / "Y-m-d H:i:s+00") → teks waktu lokal. */
export function formatDateTime(value: string): string {
    const date = new Date(
        value.includes('T') ? value : value.replace(' ', 'T'),
    );

    return Number.isNaN(date.getTime()) ? value : dateTime.format(date);
}
