import { Head, Link } from '@inertiajs/react';
import { FileText } from 'lucide-react';
import Heading from '@/components/heading';
import { formatRupiah } from '@/components/hr/pay-format';

type Props = {
    linked: boolean;
    months: { month: string; label: string; net: number; locked_at: string }[];
};

/**
 * Gaji saya (PRD-001 §16.3, HR-5c-2): slip gaji milik sendiri untuk bulan yang sudah dikunci Admin SDM.
 */
export default function MyPay({ linked, months }: Props) {
    return (
        <>
            <Head title="Gaji saya" />

            <div className="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-4 p-4">
                <Heading
                    title="Gaji saya"
                    description="Slip gaji muncul setelah rekap bulan itu dikunci Admin SDM. Bila ada yang tidak sesuai, hubungi Admin SDM."
                />

                {!linked ? (
                    <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                        Akun ini belum tertaut ke data pegawai. Hubungi Admin
                        SDM bila Anda pegawai yayasan.
                    </p>
                ) : months.length === 0 ? (
                    <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                        Belum ada slip gaji.
                    </p>
                ) : (
                    <ul className="divide-y rounded-xl border">
                        {months.map((item) => (
                            <li key={item.month}>
                                <Link
                                    href={`/gaji-saya/${item.month}`}
                                    className="flex flex-wrap items-center justify-between gap-2 p-4 text-sm hover:bg-muted/50"
                                >
                                    <span className="flex items-center gap-2 font-medium">
                                        <FileText className="size-4 text-muted-foreground" />
                                        {item.label}
                                    </span>
                                    <span className="font-medium tabular-nums">
                                        {formatRupiah(item.net)}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}
