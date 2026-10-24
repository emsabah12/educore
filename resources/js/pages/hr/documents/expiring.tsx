import { Head, Link } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';

type Row = {
    document_id: string;
    employee_id: string;
    employee_name: string;
    employee_number: string | null;
    organization: string | null;
    type: string;
    title: string;
    number: string | null;
    valid_until: string;
    days_left: number;
    expiry: 'HABIS' | 'SEGERA';
};

type Props = {
    rows: Row[];
    today: string;
    /** Batas "segera habis" dalam hari. */
    days: number;
};

const dateFormat = new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium' });

/** "2026-10-24" → "24 Okt 2026" tanpa geser zona waktu. */
function formatDate(value: string): string {
    const [year, month, day] = value.slice(0, 10).split('-').map(Number);

    return dateFormat.format(new Date(year, month - 1, day));
}

function remaining(row: Row): string {
    if (row.days_left < 0) {
        return `lewat ${Math.abs(row.days_left)} hari`;
    }

    return row.days_left === 0
        ? 'habis hari ini'
        : `${row.days_left} hari lagi`;
}

/**
 * Dokumen akan habis (PRD-001 §15.2, HR-2b): dokumen pegawai aktif yang sudah habis atau habis dalam
 * beberapa hari ke depan. Hanya Admin SDM / Admin Yayasan.
 */
export default function ExpiringDocuments({ rows, days }: Props) {
    const expired = rows.filter((row) => row.expiry === 'HABIS');
    const expiring = rows.filter((row) => row.expiry === 'SEGERA');

    return (
        <>
            <Head title="Dokumen akan habis" />

            <div className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-4 p-4">
                <Heading
                    title="Dokumen akan habis"
                    description={`Dokumen pegawai aktif yang masa berlakunya sudah habis atau habis dalam ${days} hari. Perpanjang lewat detail pegawai: unggah dokumen baru, lalu batalkan yang lama bila perlu. Kontrak yang habis tidak memberhentikan pegawai otomatis.`}
                />

                {rows.length === 0 ? (
                    <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                        Tidak ada dokumen yang habis dalam {days} hari ke depan.
                    </p>
                ) : (
                    <>
                        <Group title="Sudah habis" rows={expired} />
                        <Group
                            title={`Habis dalam ${days} hari`}
                            rows={expiring}
                        />
                    </>
                )}
            </div>
        </>
    );
}

function Group({ title, rows }: { title: string; rows: Row[] }) {
    if (rows.length === 0) {
        return null;
    }

    return (
        <section aria-label={title} className="space-y-2">
            <h2 className="text-base font-medium">
                {title} ({rows.length})
            </h2>
            <ul className="divide-y rounded-xl border">
                {rows.map((row) => (
                    <li
                        key={row.document_id}
                        className="flex flex-col gap-2 p-3 text-sm sm:flex-row sm:items-start sm:justify-between"
                    >
                        <div className="min-w-0 space-y-1">
                            <Link
                                href={`/pegawai/${row.employee_id}`}
                                className="font-medium break-words underline-offset-4 hover:underline"
                            >
                                {row.employee_name}
                            </Link>
                            <p className="text-xs text-muted-foreground">
                                {[row.employee_number, row.organization]
                                    .filter(Boolean)
                                    .join(' · ') || 'Tanpa penempatan utama'}
                            </p>
                            <p className="break-words">
                                {row.type}: {row.title}
                                {row.number && (
                                    <span className="text-muted-foreground">
                                        {' '}
                                        · No. {row.number}
                                    </span>
                                )}
                            </p>
                        </div>
                        <div className="flex shrink-0 flex-wrap items-center gap-2 sm:flex-col sm:items-end">
                            <span className="tabular-nums">
                                {formatDate(row.valid_until)}
                            </span>
                            {row.expiry === 'HABIS' ? (
                                <Badge variant="destructive">
                                    {remaining(row)}
                                </Badge>
                            ) : (
                                <Badge className="bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-100">
                                    {remaining(row)}
                                </Badge>
                            )}
                        </div>
                    </li>
                ))}
            </ul>
        </section>
    );
}
