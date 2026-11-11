import { Form } from '@inertiajs/react';
import { CircleAlert, ClipboardList, LogOut, Pencil } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { selectClass } from '@/components/native-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/*
 * Checklist offboarding pegawai keluar (PRD-001 §18.2, HR-7b). Hanya Admin SDM / Admin Yayasan; server tetap
 * memeriksa ulang. Item "Nonaktifkan akun login" tercentang otomatis bila akunnya sudah nonaktif (OD-HR-15).
 */

export type OffboardingTaskStatus = 'BELUM' | 'SELESAI' | 'TIDAK_PERLU';

export type OffboardingData = {
    checklists: {
        id: string;
        end_date: string;
        reason: string | null;
        tasks: {
            id: string;
            code: string;
            name: string;
            status: OffboardingTaskStatus;
            auto: boolean;
            note: string | null;
            done_by: string | null;
            done_on: string | null;
        }[];
        done: number;
        total: number;
        completed: boolean;
    }[];
    canStart: boolean;
    checks: {
        account: 'NONE' | 'ACTIVE' | 'INACTIVE';
        membership_id: string | null;
        duties: string[];
        open_leaves: number;
    };
    canManageAccess: boolean;
    noteMax: number;
};

const STATUS_LABEL: Record<OffboardingTaskStatus, string> = {
    BELUM: 'Belum',
    SELESAI: 'Selesai',
    TIDAK_PERLU: 'Tidak perlu',
};

const dateFormat = new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium' });

function formatDate(value: string | null): string {
    if (!value) {
        return '–';
    }

    const [year, month, day] = value.slice(0, 10).split('-').map(Number);

    return dateFormat.format(new Date(year, month - 1, day));
}

function StatusBadge({ status }: { status: OffboardingTaskStatus }) {
    if (status === 'SELESAI') {
        return <Badge>Selesai</Badge>;
    }

    if (status === 'TIDAK_PERLU') {
        return <Badge variant="secondary">Tidak perlu</Badge>;
    }

    return <Badge variant="outline">Belum</Badge>;
}

export function OffboardingSection({
    employeeId,
    data,
}: {
    employeeId: string;
    data: OffboardingData;
}) {
    const [current, ...older] = data.checklists;
    const { checks } = data;
    const warnings = [
        ...(checks.account === 'ACTIVE'
            ? ['Akun login pegawai ini masih aktif.']
            : []),
        ...checks.duties.map((duty) => `Masih tercatat: ${duty}.`),
        ...(checks.open_leaves > 0
            ? [
                  `${checks.open_leaves} pengajuan cuti masih menunggu atau melewati tanggal berhenti.`,
              ]
            : []),
    ];

    return (
        <section
            aria-label="Offboarding"
            className="space-y-4 rounded-xl border p-4"
        >
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-base font-medium">Offboarding</h2>
                {current && (
                    <Badge variant={current.completed ? 'default' : 'outline'}>
                        {current.completed
                            ? 'Selesai'
                            : `Berjalan · ${current.done}/${current.total}`}
                    </Badge>
                )}
            </div>

            {warnings.length > 0 && (
                <div className="space-y-1 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100">
                    <p className="flex items-center gap-1.5 font-medium">
                        <CircleAlert className="size-4" />
                        Perlu diperiksa
                    </p>
                    <ul className="list-disc space-y-0.5 pl-5">
                        {warnings.map((warning) => (
                            <li key={warning}>{warning}</li>
                        ))}
                    </ul>
                </div>
            )}

            {checks.account === 'ACTIVE' &&
                checks.membership_id &&
                (data.canManageAccess ? (
                    <Form
                        action={`/akses/anggota/${checks.membership_id}/akun/nonaktifkan`}
                        method="post"
                        options={{ preserveScroll: true }}
                    >
                        {({ processing, errors }) => (
                            <div className="space-y-1">
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    size="sm"
                                    disabled={processing}
                                >
                                    <LogOut />
                                    {processing
                                        ? 'Menonaktifkan…'
                                        : 'Nonaktifkan akun login'}
                                </Button>
                                <InputError
                                    message={
                                        Object.values(errors)[0] as
                                            | string
                                            | undefined
                                    }
                                />
                            </div>
                        )}
                    </Form>
                ) : (
                    <p className="text-xs text-muted-foreground">
                        Minta Admin Yayasan menonaktifkan akun login pegawai ini
                        (Kelola akses).
                    </p>
                ))}

            {data.canStart && (
                <Form
                    action={`/pegawai/${employeeId}/offboarding`}
                    method="post"
                    options={{ preserveScroll: true }}
                    className="space-y-2 rounded-lg border border-dashed p-3 text-sm"
                >
                    {({ processing, errors }) => (
                        <>
                            <p className="text-muted-foreground">
                                Pegawai ini berhenti sebelum checklist
                                offboarding tersedia.
                            </p>
                            <Button
                                type="submit"
                                size="sm"
                                variant="outline"
                                disabled={processing}
                            >
                                <ClipboardList />
                                Buat checklist offboarding
                            </Button>
                            <InputError message={errors.form} />
                        </>
                    )}
                </Form>
            )}

            {current && (
                <Checklist
                    employeeId={employeeId}
                    checklist={current}
                    noteMax={data.noteMax}
                />
            )}

            {older.length > 0 && (
                <details className="text-sm">
                    <summary className="cursor-pointer text-muted-foreground">
                        Checklist sebelumnya ({older.length})
                    </summary>
                    <div className="mt-3 space-y-3">
                        {older.map((checklist) => (
                            <Checklist
                                key={checklist.id}
                                employeeId={employeeId}
                                checklist={checklist}
                                noteMax={data.noteMax}
                            />
                        ))}
                    </div>
                </details>
            )}
        </section>
    );
}

function Checklist({
    employeeId,
    checklist,
    noteMax,
}: {
    employeeId: string;
    checklist: OffboardingData['checklists'][number];
    noteMax: number;
}) {
    return (
        <div className="space-y-2">
            <p className="text-sm text-muted-foreground">
                Berhenti {formatDate(checklist.end_date)}
                {checklist.reason && ` · ${checklist.reason}`}
            </p>
            <ul className="divide-y rounded-lg border">
                {checklist.tasks.map((task) => (
                    <TaskRow
                        key={task.id}
                        employeeId={employeeId}
                        task={task}
                        noteMax={noteMax}
                    />
                ))}
            </ul>
        </div>
    );
}

function TaskRow({
    employeeId,
    task,
    noteMax,
}: {
    employeeId: string;
    task: OffboardingData['checklists'][number]['tasks'][number];
    noteMax: number;
}) {
    const [editing, setEditing] = useState(false);

    return (
        <li className="space-y-2 p-3 text-sm">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0 space-y-0.5">
                    <p className="font-medium break-words">{task.name}</p>
                    <p className="text-xs text-muted-foreground">
                        {task.auto
                            ? 'Otomatis: akun login sudah nonaktif atau tidak ada.'
                            : task.status === 'BELUM'
                              ? 'Belum dikerjakan'
                              : `${STATUS_LABEL[task.status]}${task.done_on ? ` ${formatDate(task.done_on)}` : ''}${task.done_by ? ` · ${task.done_by}` : ''}`}
                    </p>
                    {task.note && (
                        <p className="text-xs break-words text-muted-foreground">
                            Catatan: {task.note}
                        </p>
                    )}
                </div>
                <div className="flex items-center gap-2">
                    <StatusBadge status={task.status} />
                    {!task.auto && !editing && (
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={() => setEditing(true)}
                            aria-label={`Ubah ${task.name}`}
                        >
                            <Pencil />
                            Ubah
                        </Button>
                    )}
                </div>
            </div>

            {editing && (
                <Form
                    action={`/pegawai/${employeeId}/offboarding/${task.id}`}
                    method="post"
                    errorBag={`task-${task.id}`}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setEditing(false)}
                    className="grid gap-3 rounded-lg border bg-muted/20 p-3 sm:grid-cols-[10rem_minmax(0,1fr)]"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-1.5">
                                <Label htmlFor={`task-status-${task.id}`}>
                                    Status
                                </Label>
                                <select
                                    id={`task-status-${task.id}`}
                                    name="status"
                                    className={selectClass}
                                    defaultValue={
                                        task.status === 'BELUM'
                                            ? 'SELESAI'
                                            : task.status
                                    }
                                >
                                    <option value="SELESAI">Selesai</option>
                                    <option value="TIDAK_PERLU">
                                        Tidak perlu
                                    </option>
                                    <option value="BELUM">Belum</option>
                                </select>
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor={`task-note-${task.id}`}>
                                    Catatan (opsional)
                                </Label>
                                <Input
                                    id={`task-note-${task.id}`}
                                    name="note"
                                    defaultValue={task.note ?? ''}
                                    maxLength={noteMax}
                                    placeholder="mis. Laptop diterima bagian TU"
                                />
                            </div>
                            <InputError
                                className="sm:col-span-2"
                                message={
                                    errors.status ?? errors.note ?? errors.form
                                }
                            />
                            <div className="flex flex-wrap gap-2 sm:col-span-2">
                                <Button
                                    type="submit"
                                    size="sm"
                                    disabled={processing}
                                >
                                    Simpan
                                </Button>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={() => setEditing(false)}
                                >
                                    Batal
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            )}
        </li>
    );
}
