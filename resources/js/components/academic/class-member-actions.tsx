import type { Option } from '@/components/academic/student-actions';
import {
    ActionForm,
    Field,
    SelectOptions,
} from '@/components/academic/student-actions';
import { Input } from '@/components/ui/input';

/*
 * Pindah kelas & keluarkan dari kelas (AC-1d-b, PRD-002 §4.2). Dipakai di detail kelas dan detail
 * siswa. Aturan tanggal tetap dicek server; untuk tahun ajaran yang belum mulai tanggal tidak
 * ditanyakan (otomatis tanggal masuk kelasnya).
 */

type MemberRef = {
    id: string;
    name: string;
    className: string;
    startDate: string;
};

export function MoveMemberForm({
    member,
    targets,
    today,
    futureYear,
    onDone,
}: {
    member: MemberRef;
    targets: Option[];
    today: string;
    futureYear: boolean;
    onDone: () => void;
}) {
    return (
        <ActionForm
            title={`Pindahkan ${member.name} dari ${member.className}`}
            action={`/kelas/anggota/${member.id}/pindah`}
            errorBag="moveMember"
            submitLabel="Pindahkan"
            onDone={onDone}
            note="Keanggotaan di kelas lama ditutup dan di kelas baru dibuka pada tanggal yang sama. Hanya ke kelas aktif di lembaga dan tahun ajaran yang sama."
        >
            {(errors) => (
                <>
                    <Field
                        id={`mv-target-${member.id}`}
                        label="Kelas tujuan"
                        required
                        error={errors.target_class_id}
                    >
                        <SelectOptions
                            id={`mv-target-${member.id}`}
                            name="target_class_id"
                            options={targets}
                            placeholder={
                                targets.length === 0
                                    ? 'Tidak ada kelas aktif lain'
                                    : 'Pilih kelas'
                            }
                            defaultValue={
                                targets.length === 1 ? targets[0].value : ''
                            }
                            required
                        />
                    </Field>
                    {!futureYear && (
                        <DateField
                            id={`mv-date-${member.id}`}
                            label="Tanggal pindah"
                            min={member.startDate}
                            max={today}
                            error={errors.date}
                        />
                    )}
                </>
            )}
        </ActionForm>
    );
}

export function RemoveMemberForm({
    member,
    today,
    futureYear,
    onDone,
}: {
    member: MemberRef;
    today: string;
    futureYear: boolean;
    onDone: () => void;
}) {
    return (
        <ActionForm
            title={`Keluarkan ${member.name} dari ${member.className}`}
            action={`/kelas/anggota/${member.id}/keluar`}
            errorBag="removeMember"
            submitLabel="Keluarkan dari kelas"
            destructive
            onDone={onDone}
            note="Hanya keanggotaan kelas yang ditutup; siswa tetap terdaftar aktif di lembaga. Bila siswa keluar dari sekolah, gunakan Tutup pendaftaran di detail siswa."
        >
            {(errors) =>
                futureYear ? (
                    <p className="text-sm sm:col-span-2">
                        Tahun ajaran ini belum mulai, jadi tidak perlu tanggal.
                    </p>
                ) : (
                    <DateField
                        id={`rm-date-${member.id}`}
                        label="Tanggal keluar dari kelas"
                        min={member.startDate}
                        max={today}
                        error={errors.date}
                    />
                )
            }
        </ActionForm>
    );
}

function DateField({
    id,
    label,
    min,
    max,
    error,
}: {
    id: string;
    label: string;
    min: string;
    max: string;
    error?: string;
}) {
    return (
        <Field id={id} label={label} required error={error}>
            <Input
                id={id}
                name="date"
                type="date"
                min={min}
                max={max}
                defaultValue={max}
                required
            />
        </Field>
    );
}
