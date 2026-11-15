import { LoaderCircle, Plus, Search, UserCheck } from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    Empty,
    FacilityDialog,
    Field,
} from '@/components/dormitory/facility-forms';
import type { SupervisorRow } from '@/components/dormitory/types';
import { dateText } from '@/components/dormitory/types';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Candidate = {
    membership_id: string;
    name: string;
    detail: string;
    blocked: string | null;
};

const SEARCH_MIN = 3;

/** Badge kecil di samping nama wali kamar. */
export function SupervisorBadges({ row }: { row: SupervisorRow }) {
    return (
        <>
            {row.lives_here && (
                <Badge variant="secondary">Tinggal di kamar ini</Badge>
            )}
            {!row.employee_active && (
                <Badge variant="outline" className="text-destructive">
                    Bukan pegawai aktif
                </Badge>
            )}
        </>
    );
}

/**
 * Bagian Wali kamar di detail kamar (PRD-008 §17.3): wali kamar aktif dengan aksi Tambah & Akhiri (pop-up).
 * Mengawasi tidak memakai kapasitas; yang juga tinggal di kamar ditempatkan lewat Tempatkan → Pengawas.
 */
export function SupervisorSection({
    roomId,
    roomCode,
    gender,
    supervisors,
    max,
    canManage,
    acceptsChanges,
    today,
    noteMax,
}: {
    roomId: string;
    roomCode: string;
    gender: 'L' | 'P';
    supervisors: SupervisorRow[];
    max: number;
    canManage: boolean;
    acceptsChanges: boolean;
    today: string;
    noteMax: number;
}) {
    const [adding, setAdding] = useState(false);
    const [ending, setEnding] = useState<SupervisorRow | null>(null);
    const canAdd = canManage && acceptsChanges && supervisors.length < max;

    return (
        <section className="space-y-2 rounded-xl border p-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="flex items-center gap-2 text-sm font-semibold">
                    <UserCheck className="size-4 text-muted-foreground" />
                    Wali kamar
                    <span className="font-normal text-muted-foreground">
                        {supervisors.length}/{max}
                    </span>
                </h2>
                {canAdd && (
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() => setAdding(true)}
                    >
                        <Plus />
                        Tambah wali kamar
                    </Button>
                )}
            </div>

            {supervisors.length === 0 ? (
                <Empty>
                    Belum ada wali kamar.
                    {canAdd &&
                        ` Wali kamar harus pegawai aktif ${gender === 'L' ? 'laki-laki' : 'perempuan'}.`}
                </Empty>
            ) : (
                <ul className="divide-y">
                    {supervisors.map((row) => (
                        <li
                            key={row.id}
                            className="flex flex-wrap items-center justify-between gap-2 py-2"
                        >
                            <div className="min-w-0 space-y-0.5">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="font-medium break-words">
                                        {row.name}
                                    </span>
                                    <SupervisorBadges row={row} />
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    Sejak {dateText(row.start_on)}
                                    {row.note && ` · ${row.note}`}
                                </p>
                            </div>
                            {canManage && (
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    onClick={() => setEnding(row)}
                                >
                                    Akhiri
                                </Button>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            {adding && (
                <AddSupervisorDialog
                    roomId={roomId}
                    roomCode={roomCode}
                    gender={gender}
                    today={today}
                    noteMax={noteMax}
                    onClose={() => setAdding(false)}
                />
            )}
            {ending && (
                <EndSupervisorDialog
                    row={ending}
                    roomCode={roomCode}
                    today={today}
                    noteMax={noteMax}
                    onClose={() => setEnding(null)}
                />
            )}
        </section>
    );
}

function AddSupervisorDialog({
    roomId,
    roomCode,
    gender,
    today,
    noteMax,
    onClose,
}: {
    roomId: string;
    roomCode: string;
    gender: 'L' | 'P';
    today: string;
    noteMax: number;
    onClose: () => void;
}) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<Candidate[] | null>(null);
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);
    const [chosen, setChosen] = useState<Candidate | null>(null);

    useEffect(() => {
        const term = query.trim();

        if (term.length < SEARCH_MIN) {
            setResults(null);

            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            setLoading(true);
            setFailed(false);
            fetch(
                `/asrama/kamar/${roomId}/wali-kamar/calon?${new URLSearchParams({ q: term })}`,
                {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                    signal: controller.signal,
                },
            )
                .then((response) => {
                    if (!response.ok) {
                        throw new Error(String(response.status));
                    }

                    return response.json() as Promise<{
                        candidates: Candidate[];
                    }>;
                })
                .then((data) => setResults(data.candidates))
                .catch((error: unknown) => {
                    if (
                        !(error instanceof DOMException) ||
                        error.name !== 'AbortError'
                    ) {
                        setFailed(true);
                        setResults(null);
                    }
                })
                .finally(() => setLoading(false));
        }, 300);

        return () => {
            controller.abort();
            window.clearTimeout(timer);
        };
    }, [query, roomId]);

    return (
        <FacilityDialog
            open
            onClose={onClose}
            title={`Tambah wali kamar ${roomCode}`}
            description={`Pegawai aktif ${gender === 'L' ? 'laki-laki' : 'perempuan'}. Mengawasi tidak memakai kapasitas kamar.`}
            action={`/asrama/kamar/${roomId}/wali-kamar`}
            errorBag="addSupervisor"
            submitLabel="Tambahkan"
        >
            {(errors) => (
                <>
                    <input
                        type="hidden"
                        name="membership_id"
                        value={chosen?.membership_id ?? ''}
                    />
                    {chosen ? (
                        <div className="grid gap-1.5">
                            <Label>Pegawai</Label>
                            <div className="flex items-center justify-between gap-2 rounded-md border bg-muted/40 p-3">
                                <span className="font-medium break-words">
                                    {chosen.name}
                                </span>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    onClick={() => setChosen(null)}
                                >
                                    Ganti
                                </Button>
                            </div>
                            <InputError message={errors.membership_id} />
                        </div>
                    ) : (
                        <div className="grid gap-1.5">
                            <Label htmlFor="dm-sup-q">Cari pegawai</Label>
                            <div className="relative">
                                <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                                <Input
                                    id="dm-sup-q"
                                    type="search"
                                    className="pl-8"
                                    placeholder="Ketik minimal 3 huruf nama"
                                    value={query}
                                    maxLength={100}
                                    autoComplete="off"
                                    autoFocus
                                    onChange={(event) =>
                                        setQuery(event.target.value)
                                    }
                                />
                                {loading && (
                                    <LoaderCircle className="absolute top-1/2 right-2.5 size-4 -translate-y-1/2 animate-spin text-muted-foreground" />
                                )}
                            </div>
                            {failed && (
                                <p className="text-sm text-destructive">
                                    Pencarian gagal. Periksa koneksi lalu coba
                                    lagi.
                                </p>
                            )}
                            {results !== null &&
                                (results.length === 0 ? (
                                    <p className="rounded-md bg-muted p-3 text-sm text-muted-foreground">
                                        Tidak ditemukan. Hanya pegawai aktif
                                        yang muncul.
                                    </p>
                                ) : (
                                    <ul className="max-h-60 divide-y overflow-y-auto rounded-md border">
                                        {results.map((candidate) => (
                                            <li key={candidate.membership_id}>
                                                <button
                                                    type="button"
                                                    disabled={
                                                        candidate.blocked !==
                                                        null
                                                    }
                                                    onClick={() =>
                                                        setChosen(candidate)
                                                    }
                                                    className="flex min-h-11 w-full flex-col items-start px-3 py-2 text-left hover:bg-muted disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:bg-transparent"
                                                >
                                                    <span className="text-sm font-medium">
                                                        {candidate.name}
                                                    </span>
                                                    {candidate.blocked && (
                                                        <span className="text-xs text-destructive">
                                                            Tidak bisa dipilih:{' '}
                                                            {candidate.blocked}
                                                        </span>
                                                    )}
                                                </button>
                                            </li>
                                        ))}
                                    </ul>
                                ))}
                            <InputError message={errors.membership_id} />
                        </div>
                    )}

                    <Field
                        id="dm-sup-date"
                        label="Tanggal mulai"
                        hint="Paling jauh 1 tahun ke belakang."
                        error={errors.date}
                        required
                    >
                        <Input
                            id="dm-sup-date"
                            name="date"
                            type="date"
                            defaultValue={today}
                            max={today}
                            required
                        />
                    </Field>
                    <Field
                        id="dm-sup-note"
                        label="Keterangan"
                        hint="Opsional."
                        error={errors.note}
                    >
                        <Input
                            id="dm-sup-note"
                            name="note"
                            maxLength={noteMax}
                        />
                    </Field>
                </>
            )}
        </FacilityDialog>
    );
}

function EndSupervisorDialog({
    row,
    roomCode,
    today,
    noteMax,
    onClose,
}: {
    row: SupervisorRow;
    roomCode: string;
    today: string;
    noteMax: number;
    onClose: () => void;
}) {
    return (
        <FacilityDialog
            open
            onClose={onClose}
            title={`Akhiri ${row.name} sebagai wali kamar ${roomCode}?`}
            description={`Bertugas sejak ${dateText(row.start_on)}. Penugasan tetap tercatat di riwayat.${row.lives_here ? ' Ia tetap tinggal di kamar ini; check-out terpisah.' : ''}`}
            action={`/asrama/wali-kamar/${row.id}/akhiri`}
            errorBag="endSupervisor"
            submitLabel="Akhiri penugasan"
        >
            {(errors) => (
                <>
                    <Field
                        id="dm-sup-end-date"
                        label="Tanggal selesai"
                        error={errors.date}
                        required
                    >
                        <Input
                            id="dm-sup-end-date"
                            name="date"
                            type="date"
                            defaultValue={today}
                            min={row.start_on}
                            max={today}
                            required
                        />
                    </Field>
                    <Field
                        id="dm-sup-end-note"
                        label="Keterangan"
                        hint="Opsional."
                        error={errors.note}
                    >
                        <Input
                            id="dm-sup-end-note"
                            name="note"
                            maxLength={noteMax}
                        />
                    </Field>
                </>
            )}
        </FacilityDialog>
    );
}

/** Riwayat wali kamar kamar ini (tab Riwayat). */
export function SupervisorHistory({ rows }: { rows: SupervisorRow[] }) {
    if (rows.length === 0) {
        return null;
    }

    return (
        <section className="space-y-2">
            <h2 className="text-sm font-semibold">Riwayat wali kamar</h2>
            <ul className="grid gap-2">
                {rows.map((row) => (
                    <li key={row.id} className="rounded-xl border p-3">
                        <p className="font-medium break-words">{row.name}</p>
                        <p className="text-xs text-muted-foreground">
                            {dateText(row.start_on)} – {dateText(row.end_on)}
                            {row.note && ` · ${row.note}`}
                        </p>
                    </li>
                ))}
            </ul>
        </section>
    );
}
