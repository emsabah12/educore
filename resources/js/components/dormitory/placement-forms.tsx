import { LoaderCircle, Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import { ConfirmAction } from '@/components/confirm-action';
import {
    FacilityDialog,
    Field,
    Select,
} from '@/components/dormitory/facility-forms';
import type {
    Option,
    PlacementRow,
    ResourceRow,
} from '@/components/dormitory/types';
import { dateText } from '@/components/dormitory/types';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

export type Basis = 'BED' | 'LOKER' | 'BED_LOKER';

/** Bed/loker kamar beserta pemegangnya; dipakai semua pop-up penempatan. */
export type RoomResources = {
    basis: Basis;
    beds: ResourceRow[];
    lockers: ResourceRow[];
    /** id bed/loker → id penempatan yang memegangnya (Aktif/Direncanakan). */
    heldBy: Record<string, string>;
    suggestion: { bed: string | null; locker: string | null };
};

type Candidate = {
    membership_id: string;
    name: string;
    detail: string;
    active_in: string | null;
    planned_in: string | null;
    blocked: string | null;
};

const SEARCH_MIN = 3;

/**
 * Pilihan bed/loker yang bisa dipegang: aktif, tersedia, dan tidak dipegang penempatan lain. Untuk yang wajib
 * (menurut dasar kapasitas) pilihan kosong = otomatis (saran server); untuk yang tidak wajib = tanpa.
 */
function ResourcePicker({
    kind,
    resources,
    current,
    placementId,
    error,
    prefix,
}: {
    kind: 'bed' | 'loker';
    resources: RoomResources;
    current: string | null;
    placementId: string | null;
    error?: string;
    prefix: string;
}) {
    const rows = kind === 'bed' ? resources.beds : resources.lockers;
    const required =
        kind === 'bed'
            ? resources.basis !== 'LOKER'
            : resources.basis !== 'BED';
    const label = kind === 'bed' ? 'Bed' : 'Loker';
    const free = rows.filter(
        (row) =>
            row.is_active &&
            row.status === 'TERSEDIA' &&
            (resources.heldBy[row.id] === undefined ||
                resources.heldBy[row.id] === placementId),
    );

    if (rows.length === 0 && !required) {
        return null;
    }

    const suggested = rows.find(
        (row) =>
            row.id ===
            (kind === 'bed'
                ? resources.suggestion.bed
                : resources.suggestion.locker),
    );
    const usable = free.some((row) => row.id === current) ? current : null;
    const emptyLabel = required
        ? suggested && placementId === null
            ? `Otomatis (${suggested.code})`
            : 'Otomatis (yang pertama kosong)'
        : `Tanpa ${label.toLowerCase()}`;

    return (
        <Field
            id={`${prefix}-${kind}`}
            label={label}
            hint={
                required && free.length === 0
                    ? `Tidak ada ${label.toLowerCase()} kosong di kamar ini.`
                    : undefined
            }
            error={error}
        >
            <Select
                id={`${prefix}-${kind}`}
                name={kind === 'bed' ? 'bed_id' : 'locker_id'}
                defaultValue={usable ?? ''}
                options={[
                    { value: '', label: emptyLabel },
                    ...free.map((row) => ({ value: row.id, label: row.code })),
                ]}
            />
        </Field>
    );
}

/**
 * Pop-up Tempatkan (PRD-008 §16.4): cari santri/pegawai (min. 3 huruf), pilih langsung tinggal atau rencana,
 * tanggal, bed & loker. Orang yang tidak bisa dipilih tetap tampil dengan alasannya.
 */
export function PlaceDialog({
    open,
    onClose,
    roomId,
    roomCode,
    allowSupervisor,
    categories,
    resources,
    today,
    noteMax,
}: {
    open: boolean;
    onClose: () => void;
    roomId: string;
    roomCode: string;
    allowSupervisor: boolean;
    categories: Option[];
    resources: RoomResources;
    today: string;
    noteMax: number;
}) {
    const [category, setCategory] = useState('PENGHUNI');
    const [mode, setMode] = useState<'langsung' | 'rencana'>('langsung');
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<Candidate[] | null>(null);
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);
    const [chosen, setChosen] = useState<Candidate | null>(null);

    useEffect(() => {
        const term = query.trim();

        if (!open || term.length < SEARCH_MIN) {
            setResults(null);

            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            setLoading(true);
            setFailed(false);
            fetch(
                `/asrama/kamar/${roomId}/calon?${new URLSearchParams({ q: term, kategori: category })}`,
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
    }, [open, query, category, roomId]);

    const reset = () => {
        setQuery('');
        setResults(null);
        setChosen(null);
        setMode('langsung');
        setCategory('PENGHUNI');
        onClose();
    };
    const supervisor = category === 'PENGAWAS';

    return (
        <FacilityDialog
            open={open}
            onClose={reset}
            title={`Tempatkan di kamar ${roomCode}`}
            description="Santri harus terdaftar aktif di lembaga milik asrama ini, dan jenis kelaminnya sesuai gedung."
            action={`/asrama/kamar/${roomId}/penghuni`}
            errorBag="placeResident"
            submitLabel={mode === 'rencana' ? 'Simpan rencana' : 'Tempatkan'}
        >
            {(errors) => (
                <>
                    <input type="hidden" name="category" value={category} />
                    <input type="hidden" name="mode" value={mode} />
                    <input
                        type="hidden"
                        name="membership_id"
                        value={chosen?.membership_id ?? ''}
                    />

                    {allowSupervisor && (
                        <Choice
                            label="Kategori"
                            value={category}
                            options={categories}
                            onChange={(value) => {
                                setCategory(value);
                                setChosen(null);
                            }}
                        />
                    )}

                    {chosen ? (
                        <div className="grid gap-1.5">
                            <Label>{supervisor ? 'Pegawai' : 'Santri'}</Label>
                            <div className="flex items-start justify-between gap-2 rounded-md border bg-muted/40 p-3">
                                <div className="min-w-0">
                                    <p className="font-medium break-words">
                                        {chosen.name}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {chosen.detail}
                                    </p>
                                    {chosen.active_in && (
                                        <p className="text-xs text-muted-foreground">
                                            Sedang tinggal di kamar{' '}
                                            {chosen.active_in} — hanya bisa
                                            dibuat rencana.
                                        </p>
                                    )}
                                </div>
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
                            <Label htmlFor="dm-place-q">
                                Cari {supervisor ? 'pegawai' : 'santri'}
                            </Label>
                            <div className="relative">
                                <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                                <Input
                                    id="dm-place-q"
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
                            <CandidateList
                                results={results}
                                failed={failed}
                                tooShort={query.trim().length < SEARCH_MIN}
                                onPick={(candidate) => {
                                    setChosen(candidate);

                                    if (candidate.active_in) {
                                        setMode('rencana');
                                    }
                                }}
                            />
                            <InputError message={errors.membership_id} />
                        </div>
                    )}

                    <Choice
                        label="Jenis penempatan"
                        value={mode}
                        options={[
                            {
                                value: 'langsung',
                                label: 'Langsung tinggal',
                            },
                            { value: 'rencana', label: 'Rencana' },
                        ]}
                        disabled={chosen?.active_in ? ['langsung'] : []}
                        onChange={(value) =>
                            setMode(value as 'langsung' | 'rencana')
                        }
                    />
                    <p className="-mt-2 text-xs text-muted-foreground">
                        {mode === 'rencana'
                            ? 'Rencana memesan bed/loker dan slot kamar. Check-in dilakukan saat orangnya datang.'
                            : 'Langsung tercatat tinggal sejak tanggal masuk.'}
                    </p>

                    <Field
                        id="dm-place-date"
                        label={
                            mode === 'rencana'
                                ? 'Rencana tanggal masuk'
                                : 'Tanggal masuk'
                        }
                        hint={
                            mode === 'rencana'
                                ? 'Boleh dikosongkan.'
                                : 'Paling jauh 1 tahun ke belakang.'
                        }
                        error={errors.date}
                        required={mode === 'langsung'}
                    >
                        <Input
                            key={mode}
                            id="dm-place-date"
                            name="date"
                            type="date"
                            defaultValue={mode === 'langsung' ? today : ''}
                            max={mode === 'langsung' ? today : undefined}
                            min={mode === 'rencana' ? today : undefined}
                            required={mode === 'langsung'}
                        />
                    </Field>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <ResourcePicker
                            kind="bed"
                            prefix="dm-place"
                            resources={resources}
                            current={null}
                            placementId={null}
                            error={errors.bed_id}
                        />
                        <ResourcePicker
                            kind="loker"
                            prefix="dm-place"
                            resources={resources}
                            current={null}
                            placementId={null}
                            error={errors.locker_id}
                        />
                    </div>

                    <Field
                        id="dm-place-note"
                        label="Catatan"
                        hint="Opsional."
                        error={errors.note}
                    >
                        <Input
                            id="dm-place-note"
                            name="note"
                            maxLength={noteMax}
                        />
                    </Field>
                </>
            )}
        </FacilityDialog>
    );
}

function CandidateList({
    results,
    failed,
    tooShort,
    onPick,
}: {
    results: Candidate[] | null;
    failed: boolean;
    tooShort: boolean;
    onPick: (candidate: Candidate) => void;
}) {
    if (failed) {
        return (
            <p className="text-sm text-destructive">
                Pencarian gagal. Periksa koneksi lalu coba lagi.
            </p>
        );
    }

    if (tooShort || results === null) {
        return null;
    }

    if (results.length === 0) {
        return (
            <p className="rounded-md bg-muted p-3 text-sm text-muted-foreground">
                Tidak ditemukan. Hanya yang terdaftar aktif di lembaga milik
                asrama ini yang muncul.
            </p>
        );
    }

    return (
        <ul className="max-h-60 divide-y overflow-y-auto rounded-md border">
            {results.map((candidate) => {
                const disabled = candidate.blocked !== null;

                return (
                    <li key={candidate.membership_id}>
                        <button
                            type="button"
                            disabled={disabled}
                            onClick={() => onPick(candidate)}
                            className="flex min-h-11 w-full flex-col items-start px-3 py-2 text-left hover:bg-muted disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:bg-transparent"
                        >
                            <span className="text-sm font-medium">
                                {candidate.name}
                            </span>
                            <span className="text-xs text-muted-foreground">
                                {candidate.detail}
                                {candidate.active_in &&
                                    ` · tinggal di ${candidate.active_in}`}
                            </span>
                            {disabled && (
                                <span className="text-xs text-destructive">
                                    Tidak bisa dipilih: {candidate.blocked}
                                </span>
                            )}
                        </button>
                    </li>
                );
            })}
        </ul>
    );
}

/** Pilihan kecil berbentuk tombol (2–3 opsi). */
function Choice({
    label,
    value,
    options,
    onChange,
    disabled = [],
}: {
    label: string;
    value: string;
    options: Option[];
    onChange: (value: string) => void;
    disabled?: string[];
}) {
    return (
        <fieldset className="grid gap-1.5">
            <legend className="mb-1.5 text-sm font-medium">{label}</legend>
            <div className="grid grid-cols-2 gap-2">
                {options.map((option) => (
                    <button
                        key={option.value}
                        type="button"
                        aria-pressed={value === option.value}
                        disabled={disabled.includes(option.value)}
                        onClick={() => onChange(option.value)}
                        className={cn(
                            'min-h-11 rounded-md border px-3 py-2 text-sm transition-colors disabled:cursor-not-allowed disabled:opacity-50',
                            value === option.value
                                ? 'border-primary bg-primary/5 font-medium text-primary dark:text-accent-foreground'
                                : 'hover:bg-muted',
                        )}
                    >
                        {option.label}
                    </button>
                ))}
            </div>
        </fieldset>
    );
}

/** Check-in rencana: tanggal masuk + bed/loker (bawaan = pesanan rencana). */
export function CheckInDialog({
    row,
    onClose,
    resources,
    today,
}: {
    row: PlacementRow;
    onClose: () => void;
    resources: RoomResources;
    today: string;
}) {
    return (
        <FacilityDialog
            open
            onClose={onClose}
            title={`Check-in ${row.name}`}
            description={
                row.planned_on
                    ? `Rencana masuk ${dateText(row.planned_on)}.`
                    : undefined
            }
            action={`/asrama/penempatan/${row.id}/check-in`}
            errorBag="checkIn"
            submitLabel="Check-in"
        >
            {(errors) => (
                <>
                    <Field
                        id="dm-in-date"
                        label="Tanggal masuk"
                        error={errors.date}
                        required
                    >
                        <Input
                            id="dm-in-date"
                            name="date"
                            type="date"
                            defaultValue={today}
                            max={today}
                            required
                        />
                    </Field>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <ResourcePicker
                            kind="bed"
                            prefix="dm-in"
                            resources={resources}
                            current={row.bed_id}
                            placementId={row.id}
                            error={errors.bed_id}
                        />
                        <ResourcePicker
                            kind="loker"
                            prefix="dm-in"
                            resources={resources}
                            current={row.locker_id}
                            placementId={row.id}
                            error={errors.locker_id}
                        />
                    </div>
                </>
            )}
        </FacilityDialog>
    );
}

/** Check-out: tanggal keluar + alasan (Lainnya wajib keterangan). */
export function CheckOutDialog({
    row,
    onClose,
    endReasons,
    today,
    noteMax,
}: {
    row: PlacementRow;
    onClose: () => void;
    endReasons: Option[];
    today: string;
    noteMax: number;
}) {
    const [reason, setReason] = useState('');

    return (
        <FacilityDialog
            open
            onClose={onClose}
            title={`Check-out ${row.name}`}
            description={`Bed/loker yang dipegang langsung kosong. Masuk sejak ${dateText(row.check_in_on)}.`}
            action={`/asrama/penempatan/${row.id}/check-out`}
            errorBag="checkOut"
            submitLabel="Check-out"
        >
            {(errors) => (
                <>
                    <Field
                        id="dm-out-date"
                        label="Tanggal keluar"
                        error={errors.date}
                        required
                    >
                        <Input
                            id="dm-out-date"
                            name="date"
                            type="date"
                            defaultValue={today}
                            min={row.check_in_on ?? undefined}
                            max={today}
                            required
                        />
                    </Field>
                    <Field
                        id="dm-out-reason"
                        label="Alasan keluar"
                        error={errors.end_reason}
                        required
                    >
                        <Select
                            id="dm-out-reason"
                            name="end_reason"
                            value={reason}
                            onChange={setReason}
                            placeholder="Pilih alasan"
                            options={endReasons}
                        />
                    </Field>
                    <Field
                        id="dm-out-note"
                        label="Keterangan"
                        hint={
                            reason === 'LAINNYA'
                                ? 'Wajib untuk alasan Lainnya.'
                                : 'Opsional.'
                        }
                        error={errors.note}
                        required={reason === 'LAINNYA'}
                    >
                        <Input
                            id="dm-out-note"
                            name="note"
                            maxLength={noteMax}
                            required={reason === 'LAINNYA'}
                        />
                    </Field>
                </>
            )}
        </FacilityDialog>
    );
}

/** Ganti bed/loker di kamar yang sama (bukan mutasi). */
export function ChangeResourcesDialog({
    row,
    onClose,
    resources,
}: {
    row: PlacementRow;
    onClose: () => void;
    resources: RoomResources;
}) {
    return (
        <FacilityDialog
            open
            onClose={onClose}
            title={`Ganti bed/loker ${row.name}`}
            description="Pindah bed/loker di kamar yang sama. Pindah kamar = mutasi (menyusul)."
            action={`/asrama/penempatan/${row.id}/bed-loker`}
            errorBag="changeResources"
            submitLabel="Simpan"
        >
            {(errors) => (
                <div className="grid gap-4 sm:grid-cols-2">
                    <ResourcePicker
                        kind="bed"
                        prefix="dm-change"
                        resources={resources}
                        current={row.bed_id}
                        placementId={row.id}
                        error={errors.bed_id}
                    />
                    <ResourcePicker
                        kind="loker"
                        prefix="dm-change"
                        resources={resources}
                        current={row.locker_id}
                        placementId={row.id}
                        error={errors.locker_id}
                    />
                </div>
            )}
        </FacilityDialog>
    );
}

/** Batalkan rencana (wajib alasan); bed/loker pesanan & slot langsung lepas. */
export function CancelPlanAction({
    row,
    noteMax,
}: {
    row: PlacementRow;
    noteMax: number;
}) {
    return (
        <ConfirmAction
            trigger={
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    className="text-destructive"
                >
                    Batalkan
                </Button>
            }
            title={`Batalkan rencana ${row.name}?`}
            description="Bed/loker yang dipesan dan slot kamar langsung lepas. Rencana tetap tercatat di riwayat."
            action={`/asrama/penempatan/${row.id}/batalkan`}
            confirmLabel="Ya, batalkan"
            destructive
            reason={{
                label: 'Alasan pembatalan',
                max: noteMax,
                placeholder: 'Mis. tidak jadi mondok',
                required: true,
            }}
            errorBag={`cancelPlan-${row.id}`}
        />
    );
}
