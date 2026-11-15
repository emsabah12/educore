import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import {
    PlacementStatusBadge,
    ResourceWarning,
    dateLine,
    resourceText,
} from '@/components/dormitory/residents';
import type { PlacementRow } from '@/components/dormitory/types';
import Heading from '@/components/heading';

type Props = {
    name: string;
    detail: string;
    rows: PlacementRow[];
};

/**
 * Riwayat hunian satu orang di asrama yang terlihat pengguna (PRD-008 §16.5; keputusan owner: riwayat hanya di
 * modul Asrama). Terbaru di atas, termasuk rencana yang dibatalkan.
 */
export default function ResidentHistory({ name, detail, rows }: Props) {
    return (
        <>
            <Head title={`Riwayat hunian ${name}`} />

            <div className="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-4 p-4">
                <Link
                    href="/asrama/penghuni"
                    className="flex w-fit items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" />
                    Penghuni
                </Link>

                <Heading
                    title={name}
                    description={`${detail} · riwayat hunian asrama`}
                />

                <ol className="grid gap-2">
                    {rows.map((row) => (
                        <li
                            key={row.id}
                            className="space-y-1 rounded-xl border p-3"
                        >
                            <div className="flex flex-wrap items-start justify-between gap-2">
                                <div>
                                    <Link
                                        href={`/asrama/kamar/${row.room_id}`}
                                        className="font-medium hover:underline"
                                    >
                                        Kamar {row.room}
                                    </Link>
                                    <p className="text-xs text-muted-foreground">
                                        {row.building} · {row.dormitory}
                                    </p>
                                </div>
                                <PlacementStatusBadge row={row} />
                            </div>
                            <p className="text-sm">
                                {row.category_label} · {resourceText(row)}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                {dateLine(row)}
                                {row.end_reason_label &&
                                    ` · ${row.end_reason_label}`}
                            </p>
                            {(row.cancel_reason || row.note) && (
                                <p className="text-xs break-words text-muted-foreground">
                                    {row.cancel_reason ?? row.note}
                                </p>
                            )}
                            <ResourceWarning row={row} />
                        </li>
                    ))}
                </ol>
            </div>
        </>
    );
}
