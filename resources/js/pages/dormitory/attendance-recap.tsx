import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import type { AttendanceKindRow } from '@/components/dormitory/attendance-kinds';
import type { AttendanceRecapProps } from '@/components/dormitory/attendance-recap';
import { AttendanceRecapView } from '@/components/dormitory/attendance-recap';
import Heading from '@/components/heading';

type Props = {
    room: { id: string; code: string };
    dormitory: { id: string; name: string };
    kinds: AttendanceKindRow[];
    recap: AttendanceRecapProps;
};

/** Kamar saya → Rekap absen (PRD-008 §22.3): rekap bulanan kamar yang diawasi wali kamar. */
export default function MyRoomAttendanceRecap({
    room,
    dormitory,
    kinds,
    recap,
}: Props) {
    const url = `/kamar-saya/${room.id}/rekap-absen`;

    return (
        <>
            <Head title={`Rekap absen ${room.code}`} />

            <div className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-4 p-4">
                <div>
                    <Link
                        href="/kamar-saya"
                        className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                    >
                        <ArrowLeft className="size-4" />
                        Kamar saya
                    </Link>
                </div>

                <Heading
                    title={`Rekap absen kamar ${room.code}`}
                    description={dormitory.name}
                />

                <AttendanceRecapView
                    recap={recap}
                    kinds={kinds}
                    url={url}
                    exportUrl={`${url}/unduh`}
                />
            </div>
        </>
    );
}
