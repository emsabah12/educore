import { Head } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import { Empty } from '@/components/dormitory/facility-forms';
import {
    PlacementStatusBadge,
    ResourceWarning,
    resourceText,
} from '@/components/dormitory/residents';
import type {
    Capacity,
    Occupancy,
    PlacementRow,
    SupervisorRow,
} from '@/components/dormitory/types';
import { occupancyText } from '@/components/dormitory/types';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';

type MyRoom = {
    room: {
        id: string;
        code: string;
        name: string | null;
        composition_label: string;
        building: { name: string; gender_label: string };
        dormitory: { name: string };
    };
    capacity: Capacity;
    occupancy: Occupancy;
    over_capacity: boolean;
    holders: PlacementRow[];
    supervisors: SupervisorRow[];
};

/**
 * Kamar saya (PRD-008 §17.3, OD-DM-09): kamar yang diawasi pengguna sebagai wali kamar, dengan daftar penghuni dan
 * rencananya. Hanya lihat; absen asrama (DM-7) dan ajukan mutasi (DM-4) menyusul.
 */
export default function MyRooms({ rooms }: { rooms: MyRoom[] }) {
    return (
        <>
            <Head title="Kamar saya" />

            <div className="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-4 p-4">
                <Heading
                    title="Kamar saya"
                    description="Kamar asrama yang Anda awasi sebagai wali kamar."
                />

                {rooms.map(
                    ({
                        room,
                        capacity,
                        occupancy,
                        over_capacity: overCapacity,
                        holders,
                        supervisors,
                    }) => {
                        const others = supervisors.filter(
                            (row) => row.employee_active,
                        );

                        return (
                            <section
                                key={room.id}
                                className="space-y-3 rounded-xl border p-4"
                            >
                                <div className="space-y-1">
                                    <h2 className="text-lg font-semibold">
                                        Kamar {room.code}
                                        {room.name && ` · ${room.name}`}
                                    </h2>
                                    <p className="text-sm text-muted-foreground">
                                        {room.building.name} (
                                        {room.building.gender_label}) ·{' '}
                                        {room.dormitory.name}
                                    </p>
                                    <div className="flex flex-wrap gap-2">
                                        <Badge variant="secondary">
                                            {occupancyText(occupancy, capacity)}
                                        </Badge>
                                        <Badge variant="secondary">
                                            {room.composition_label}
                                        </Badge>
                                    </div>
                                    {overCapacity && (
                                        <p className="flex items-center gap-1 text-sm text-destructive">
                                            <TriangleAlert className="size-4" />
                                            Kamar kelebihan muatan. Hubungi
                                            pengurus asrama.
                                        </p>
                                    )}
                                    {others.length > 1 && (
                                        <p className="text-xs text-muted-foreground">
                                            Wali kamar:{' '}
                                            {others
                                                .map((row) => row.name)
                                                .join(', ')}
                                        </p>
                                    )}
                                </div>

                                {holders.length === 0 ? (
                                    <Empty>Kamar ini belum berpenghuni.</Empty>
                                ) : (
                                    <ul className="divide-y rounded-lg border">
                                        {holders.map((row) => (
                                            <li
                                                key={row.id}
                                                className="space-y-0.5 px-3 py-2"
                                            >
                                                <div className="flex flex-wrap items-center justify-between gap-2">
                                                    <span className="font-medium break-words">
                                                        {row.name}
                                                    </span>
                                                    <PlacementStatusBadge
                                                        row={row}
                                                    />
                                                </div>
                                                <p className="text-xs text-muted-foreground">
                                                    {row.category === 'PENGAWAS'
                                                        ? 'Pengawas'
                                                        : row.detail}{' '}
                                                    · {resourceText(row)}
                                                </p>
                                                <ResourceWarning row={row} />
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </section>
                        );
                    },
                )}
            </div>
        </>
    );
}
