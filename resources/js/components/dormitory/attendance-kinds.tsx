import { Link, router } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { ConfirmAction } from '@/components/confirm-action';
import {
    FacilityDialog,
    Field,
    StatusToggle,
} from '@/components/dormitory/facility-forms';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

export type AttendanceKindRow = {
    id: string;
    name: string;
    is_active: boolean;
    used: boolean;
};

const NAME_MAX = 30;
const ACTIVE_MAX = 5;

/**
 * Jenis absen asrama di tab Pengaturan detail asrama (PRD-008 §22.1): tambah, ubah nama, urutkan,
 * nonaktifkan/aktifkan, hapus (hanya yang belum dipakai).
 */
export function AttendanceKindSettings({
    dormitoryId,
    kinds,
    canManage,
}: {
    dormitoryId: string;
    kinds: AttendanceKindRow[];
    canManage: boolean;
}) {
    const [dialog, setDialog] = useState<
        { kind: 'add' } | { kind: 'edit'; row: AttendanceKindRow } | null
    >(null);
    const close = () => setDialog(null);
    const activeCount = kinds.filter((row) => row.is_active).length;

    const move = (row: AttendanceKindRow, direction: 'up' | 'down') =>
        router.post(
            `/asrama/jenis-absen/${row.id}/urutan`,
            { direction },
            { preserveScroll: true },
        );

    return (
        <section className="space-y-3 rounded-xl border p-4 text-sm">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 className="font-semibold">Jenis absen</h2>
                    <p className="text-xs text-muted-foreground">
                        Sesi absen asrama, mis. Subuh dan Malam. Maks.{' '}
                        {ACTIVE_MAX} jenis aktif.{' '}
                        <Link
                            href={`/asrama/absen?asrama=${dormitoryId}`}
                            className="underline underline-offset-2"
                        >
                            Buka Absen asrama
                        </Link>
                    </p>
                </div>
                {canManage && activeCount < ACTIVE_MAX && (
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() => setDialog({ kind: 'add' })}
                    >
                        <Plus />
                        Tambah jenis absen
                    </Button>
                )}
            </div>

            {kinds.length === 0 ? (
                <p className="text-muted-foreground">
                    Belum ada jenis absen. Absen asrama baru bisa diisi setelah
                    jenis absen ditambahkan.
                </p>
            ) : (
                <ul className="divide-y">
                    {kinds.map((row, index) => (
                        <li
                            key={row.id}
                            className="flex flex-wrap items-center justify-between gap-2 py-2"
                        >
                            <span className="flex items-center gap-2">
                                <span className="font-medium">{row.name}</span>
                                {!row.is_active && (
                                    <Badge variant="outline">Nonaktif</Badge>
                                )}
                            </span>
                            {canManage && (
                                <span className="flex flex-wrap gap-1">
                                    <Button
                                        type="button"
                                        size="icon"
                                        variant="ghost"
                                        aria-label={`Naikkan ${row.name}`}
                                        disabled={index === 0}
                                        onClick={() => move(row, 'up')}
                                    >
                                        <ArrowUp />
                                    </Button>
                                    <Button
                                        type="button"
                                        size="icon"
                                        variant="ghost"
                                        aria-label={`Turunkan ${row.name}`}
                                        disabled={index === kinds.length - 1}
                                        onClick={() => move(row, 'down')}
                                    >
                                        <ArrowDown />
                                    </Button>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            setDialog({ kind: 'edit', row })
                                        }
                                    >
                                        <Pencil />
                                        Ubah
                                    </Button>
                                </span>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            <FacilityDialog
                open={dialog?.kind === 'add'}
                onClose={close}
                title="Tambah jenis absen"
                action={`/asrama/${dormitoryId}/jenis-absen`}
                errorBag="addAttendanceKind"
                submitLabel="Tambah"
            >
                {(errors) => (
                    <Field
                        id="kind-name"
                        label="Nama"
                        hint="Mis. Subuh, Malam, Ba'da Isya."
                        error={errors.name}
                        required
                    >
                        <Input
                            id="kind-name"
                            name="name"
                            maxLength={NAME_MAX}
                            required
                            autoFocus
                        />
                    </Field>
                )}
            </FacilityDialog>

            {dialog?.kind === 'edit' && (
                <FacilityDialog
                    open
                    onClose={close}
                    title={`Ubah jenis absen ${dialog.row.name}`}
                    action={`/asrama/jenis-absen/${dialog.row.id}`}
                    method="patch"
                    errorBag="editAttendanceKind"
                    submitLabel="Simpan"
                    footer={
                        <div className="flex flex-wrap gap-2 border-t pt-4">
                            <StatusToggle
                                active={dialog.row.is_active}
                                action={`/asrama/jenis-absen/${dialog.row.id}/status`}
                                subject={`jenis absen ${dialog.row.name}`}
                                deactivateNote="Jenis ini tidak bisa dipilih saat mengisi absen. Absen yang sudah tersimpan tetap ada di rekap."
                                errorBag="attendanceKindStatus"
                                onSuccess={close}
                            />
                            {!dialog.row.used && (
                                <ConfirmAction
                                    trigger={
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                        >
                                            <Trash2 />
                                            Hapus
                                        </Button>
                                    }
                                    title={`Hapus jenis absen ${dialog.row.name}?`}
                                    description="Jenis ini belum pernah dipakai, jadi bisa dihapus permanen."
                                    action={`/asrama/jenis-absen/${dialog.row.id}`}
                                    method="delete"
                                    confirmLabel="Ya, hapus"
                                    errorBag="attendanceKindDelete"
                                    onSuccess={close}
                                />
                            )}
                        </div>
                    }
                >
                    {(errors) => (
                        <Field
                            id="kind-edit-name"
                            label="Nama"
                            error={errors.name}
                            required
                        >
                            <Input
                                id="kind-edit-name"
                                name="name"
                                defaultValue={dialog.row.name}
                                maxLength={NAME_MAX}
                                required
                            />
                        </Field>
                    )}
                </FacilityDialog>
            )}
        </section>
    );
}
