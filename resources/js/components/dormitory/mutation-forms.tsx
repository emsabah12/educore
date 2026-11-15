import { LoaderCircle } from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    FacilityDialog,
    Field,
    Select,
} from '@/components/dormitory/facility-forms';
import type { Option, PlacementRow } from '@/components/dormitory/types';
import InputError from '@/components/input-error';
import { cn } from '@/lib/utils';

/** Batas panjang alasan (sama dengan server: ManageMutations). */
export const REASON_MAX = 500;
export const EMERGENCY_REASON_MIN = 10;

export const textareaClass =
    'min-h-20 w-full rounded-md border border-input bg-transparent px-3.5 py-2.5 text-base outline-none transition-colors placeholder:text-muted-foreground hover:border-foreground/60 focus-visible:border-primary focus-visible:ring-1 focus-visible:ring-primary md:text-sm';

/** Pengajuan terbuka per penempatan (dari server: MutationDirectory::openForPlacements). */
export type OpenMutations = Record<
    string,
    { id: string; to_room: string; requested_by: string | null }
>;

type Target = {
    id: string;
    code: string;
    name: string | null;
    building: string;
    dormitory: string;
    dormitory_id: string;
    free: number;
    blocked: string | null;
};

/**
 * Pop-up Mutasi (PRD-008 §18.3): pilih kamar tujuan (yang cocok saja, dengan sisa slot santri) dan alasan. Pilihan
 * Darurat (kategori + alasan min. 10 karakter) muncul bila pengguna berizin; darurat langsung dilaksanakan.
 */
export function MutationDialog({
    row,
    onClose,
    canRequest,
    canEmergency,
    emergencyCategories,
    hasOpenRequest,
}: {
    row: PlacementRow;
    onClose: () => void;
    canRequest: boolean;
    canEmergency: boolean;
    emergencyCategories: Option[];
    hasOpenRequest: boolean;
}) {
    const [emergency, setEmergency] = useState(!canRequest || hasOpenRequest);
    const [targets, setTargets] = useState<Target[] | null>(null);
    const [failed, setFailed] = useState(false);
    const [chosen, setChosen] = useState('');
    const [category, setCategory] = useState('');
    const kind = emergency ? 'darurat' : 'biasa';

    useEffect(() => {
        const controller = new AbortController();

        setTargets(null);
        setFailed(false);
        setChosen('');
        fetch(
            `/asrama/penempatan/${row.id}/tujuan-mutasi?${new URLSearchParams({ jenis: kind })}`,
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

                return response.json() as Promise<{ targets: Target[] }>;
            })
            .then((data) => setTargets(data.targets))
            .catch((error: unknown) => {
                if (
                    !(error instanceof DOMException) ||
                    error.name !== 'AbortError'
                ) {
                    setFailed(true);
                }
            });

        return () => controller.abort();
    }, [row.id, kind]);

    return (
        <FacilityDialog
            open
            onClose={onClose}
            title={`Mutasi ${row.name}`}
            description={`Sekarang di kamar ${row.room} (${row.dormitory}).`}
            action={
                emergency
                    ? `/asrama/penempatan/${row.id}/mutasi-darurat`
                    : `/asrama/penempatan/${row.id}/mutasi`
            }
            errorBag="mutation"
            submitLabel={emergency ? 'Pindahkan sekarang' : 'Ajukan mutasi'}
        >
            {(errors) => (
                <>
                    <input type="hidden" name="to_room_id" value={chosen} />

                    {canRequest && canEmergency && (
                        <fieldset className="grid gap-1.5">
                            <legend className="mb-1.5 text-sm font-medium">
                                Jenis mutasi
                            </legend>
                            <div className="grid grid-cols-2 gap-2">
                                {[
                                    {
                                        value: false,
                                        label: 'Biasa',
                                        disabled: hasOpenRequest,
                                    },
                                    {
                                        value: true,
                                        label: 'Darurat',
                                        disabled: false,
                                    },
                                ].map((option) => (
                                    <button
                                        key={option.label}
                                        type="button"
                                        aria-pressed={
                                            emergency === option.value
                                        }
                                        disabled={option.disabled}
                                        onClick={() =>
                                            setEmergency(option.value)
                                        }
                                        className={cn(
                                            'min-h-11 rounded-md border px-3 py-2 text-sm transition-colors disabled:cursor-not-allowed disabled:opacity-50',
                                            emergency === option.value
                                                ? 'border-primary bg-primary/5 font-medium text-primary dark:text-accent-foreground'
                                                : 'hover:bg-muted',
                                        )}
                                    >
                                        {option.label}
                                    </button>
                                ))}
                            </div>
                        </fieldset>
                    )}
                    <p className="-mt-2 text-xs text-muted-foreground">
                        {emergency
                            ? 'Darurat langsung dilaksanakan tanpa menunggu persetujuan, lalu ditandai untuk ditinjau Kepala Asrama. Aturan kamar tetap berlaku.'
                            : hasOpenRequest
                              ? 'Santri ini sudah punya pengajuan yang menunggu.'
                              : 'Pengajuan menunggu persetujuan Kepala Asrama. Kamar tujuan diperiksa ulang saat disetujui.'}
                    </p>

                    <div className="grid gap-1.5">
                        <span className="text-sm font-medium">
                            Kamar tujuan
                        </span>
                        <TargetList
                            targets={targets}
                            failed={failed}
                            chosen={chosen}
                            onChoose={setChosen}
                        />
                        <InputError message={errors.to_room_id} />
                    </div>

                    {emergency && (
                        <Field
                            id="dm-mut-category"
                            label="Kategori darurat"
                            error={errors.emergency_category}
                            required
                        >
                            <Select
                                id="dm-mut-category"
                                name="emergency_category"
                                value={category}
                                onChange={setCategory}
                                placeholder="Pilih kategori"
                                options={emergencyCategories}
                            />
                        </Field>
                    )}

                    <Field
                        id="dm-mut-reason"
                        label="Alasan"
                        hint={
                            emergency
                                ? `Minimal ${EMERGENCY_REASON_MIN} karakter.`
                                : undefined
                        }
                        error={errors.reason}
                        required
                    >
                        <textarea
                            id="dm-mut-reason"
                            name="reason"
                            className={textareaClass}
                            maxLength={REASON_MAX}
                            minLength={
                                emergency ? EMERGENCY_REASON_MIN : undefined
                            }
                            required
                        />
                    </Field>
                </>
            )}
        </FacilityDialog>
    );
}

function TargetList({
    targets,
    failed,
    chosen,
    onChoose,
}: {
    targets: Target[] | null;
    failed: boolean;
    chosen: string;
    onChoose: (id: string) => void;
}) {
    if (failed) {
        return (
            <p className="text-sm text-destructive">
                Gagal memuat kamar tujuan. Tutup lalu coba lagi.
            </p>
        );
    }

    if (targets === null) {
        return (
            <p className="flex items-center gap-2 text-sm text-muted-foreground">
                <LoaderCircle className="size-4 animate-spin" />
                Memuat kamar…
            </p>
        );
    }

    if (targets.length === 0) {
        return (
            <p className="rounded-md bg-muted p-3 text-sm text-muted-foreground">
                Tidak ada kamar lain yang cocok untuk santri ini.
            </p>
        );
    }

    return (
        <ul className="max-h-64 divide-y overflow-y-auto rounded-md border">
            {targets.map((target) => (
                <li key={target.id}>
                    <button
                        type="button"
                        disabled={target.blocked !== null}
                        aria-pressed={chosen === target.id}
                        onClick={() => onChoose(target.id)}
                        className={cn(
                            'flex min-h-11 w-full items-center justify-between gap-3 px-3 py-2 text-left disabled:cursor-not-allowed disabled:opacity-60',
                            chosen === target.id
                                ? 'bg-primary/10'
                                : 'hover:bg-muted disabled:hover:bg-transparent',
                        )}
                    >
                        <span className="min-w-0">
                            <span className="block text-sm font-medium">
                                {target.code}
                                {target.name && ` · ${target.name}`}
                            </span>
                            <span className="block text-xs text-muted-foreground">
                                {target.building} · {target.dormitory}
                            </span>
                        </span>
                        <span
                            className={cn(
                                'shrink-0 text-xs',
                                target.blocked
                                    ? 'text-destructive'
                                    : 'text-muted-foreground',
                            )}
                        >
                            {target.blocked ?? `Sisa ${target.free}`}
                        </span>
                    </button>
                </li>
            ))}
        </ul>
    );
}
