import { Head, router } from '@inertiajs/react';
import { ArrowRight, Check, Siren } from 'lucide-react';
import { useState } from 'react';
import { ConfirmAction } from '@/components/confirm-action';
import {
    Empty,
    FacilityDialog,
    Field,
    Select,
} from '@/components/dormitory/facility-forms';
import { REASON_MAX } from '@/components/dormitory/mutation-forms';
import { Pagination } from '@/components/dormitory/residents';
import type { PlacementPage } from '@/components/dormitory/types';
import { dateText } from '@/components/dormitory/types';
import Heading from '@/components/heading';
import { PageTabs } from '@/components/page-tabs';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

type Resource = { id: string; code: string };

type MutationRow = {
    id: string;
    type: 'BIASA' | 'DARURAT';
    type_label: string;
    status: 'DIAJUKAN' | 'DILAKSANAKAN' | 'DITOLAK' | 'DIBATALKAN';
    status_label: string;
    category_label: string | null;
    reason: string;
    name: string;
    from_room: string;
    from_dormitory: string;
    to_room: string;
    to_dormitory: string;
    requested_by_name: string | null;
    requested_at: string;
    decided_by_name: string | null;
    decided_at: string | null;
    decision_note: string | null;
    executed_on: string | null;
    reviewed: boolean;
    reviewed_by_name: string | null;
    reviewed_at: string | null;
    review_note: string | null;
    recent: boolean;
    can_approve: boolean;
    can_reject: boolean;
    can_cancel: boolean;
    can_review: boolean;
    resources: {
        basis: 'BED' | 'LOKER' | 'BED_LOKER';
        beds: Resource[];
        lockers: Resource[];
        suggestion: { bed: string | null; locker: string | null };
    } | null;
};

type Tab = 'menunggu' | 'darurat' | 'riwayat';

type Props = {
    tab: Tab;
    mutations: Omit<PlacementPage, 'data'> & { data: MutationRow[] };
    counts: { pending: number; unreviewed: number };
    options: { noteMax: number };
};

/** "2026-11-15 09:00:00" → "15 Nov 2026". */
function day(value: string | null) {
    return value ? dateText(value.slice(0, 10)) : '–';
}

/**
 * Mutasi kamar (PRD-008 §18.3): tab Menunggu persetujuan (Setujui · Tolak · Tarik), Darurat (Sudah ditinjau),
 * Riwayat. Mutasi baru diajukan dari halaman kamar atau Kamar saya.
 */
export default function Mutations({ tab, mutations, counts, options }: Props) {
    const [approving, setApproving] = useState<MutationRow | null>(null);
    const setTab = (value: Tab) =>
        router.get(
            '/asrama/mutasi',
            value === 'menunggu' ? {} : { tab: value },
            { preserveScroll: true },
        );

    return (
        <>
            <Head title="Mutasi kamar" />

            <div className="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-4 p-4">
                <Heading
                    title="Mutasi kamar"
                    description="Pengajuan pindah kamar dan mutasi darurat. Mutasi baru dibuat dari halaman kamar (tombol Mutasi) atau Kamar saya."
                />

                <PageTabs
                    label="Bagian mutasi"
                    tabs={[
                        {
                            value: 'menunggu',
                            label: 'Menunggu persetujuan',
                            badge: counts.pending,
                        },
                        {
                            value: 'darurat',
                            label: 'Darurat',
                            badge: counts.unreviewed,
                        },
                        { value: 'riwayat', label: 'Riwayat' },
                    ]}
                    value={tab}
                    onChange={setTab}
                />

                {mutations.data.length === 0 ? (
                    <Empty>
                        {tab === 'menunggu'
                            ? 'Tidak ada pengajuan mutasi yang menunggu.'
                            : tab === 'darurat'
                              ? 'Belum ada mutasi darurat.'
                              : 'Belum ada mutasi.'}
                    </Empty>
                ) : (
                    <ul className="grid gap-3">
                        {mutations.data.map((row) => (
                            <MutationCard
                                key={row.id}
                                row={row}
                                noteMax={options.noteMax}
                                onApprove={() => setApproving(row)}
                            />
                        ))}
                    </ul>
                )}

                <Pagination
                    page={mutations as unknown as PlacementPage}
                    label="mutasi"
                />
            </div>

            {approving && (
                <ApproveDialog
                    row={approving}
                    onClose={() => setApproving(null)}
                />
            )}
        </>
    );
}

function MutationCard({
    row,
    noteMax,
    onApprove,
}: {
    row: MutationRow;
    noteMax: number;
    onApprove: () => void;
}) {
    const flagged = row.type === 'DARURAT' && !row.reviewed;

    return (
        <li
            className={cn(
                'space-y-2 rounded-xl border p-4',
                flagged && 'border-destructive/50 bg-destructive/5',
            )}
        >
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0">
                    <p className="font-medium break-words">{row.name}</p>
                    <p className="flex flex-wrap items-center gap-1 text-sm">
                        {row.from_room}
                        <span className="text-muted-foreground">
                            ({row.from_dormitory})
                        </span>
                        <ArrowRight className="size-4 text-muted-foreground" />
                        <span className="font-medium">{row.to_room}</span>
                        <span className="text-muted-foreground">
                            ({row.to_dormitory})
                        </span>
                    </p>
                </div>
                <div className="flex flex-wrap gap-1">
                    {row.type === 'DARURAT' && (
                        <Badge variant="destructive">
                            <Siren />
                            Darurat · {row.category_label}
                        </Badge>
                    )}
                    {flagged && row.recent && (
                        <Badge variant="destructive">24 jam terakhir</Badge>
                    )}
                    {row.type === 'DARURAT' && row.reviewed && (
                        <Badge variant="secondary">Sudah ditinjau</Badge>
                    )}
                    <Badge
                        variant={
                            row.status === 'DIAJUKAN' ? 'outline' : 'secondary'
                        }
                    >
                        {row.status_label}
                    </Badge>
                </div>
            </div>

            <p className="rounded-md bg-muted/50 px-3 py-2 text-sm break-words">
                {row.reason}
            </p>

            <div className="space-y-0.5 text-xs text-muted-foreground">
                <p>
                    {row.type === 'DARURAT' ? 'Dipindahkan' : 'Diajukan'} oleh{' '}
                    {row.requested_by_name ?? '–'} · {day(row.requested_at)}
                </p>
                {row.status === 'DILAKSANAKAN' && row.type === 'BIASA' && (
                    <p>
                        Disetujui {row.decided_by_name ?? ''} · pindah{' '}
                        {day(row.executed_on)}
                    </p>
                )}
                {(row.status === 'DITOLAK' || row.status === 'DIBATALKAN') && (
                    <p>
                        {row.status_label}
                        {row.decided_by_name &&
                            ` oleh ${row.decided_by_name}`}{' '}
                        · {day(row.decided_at)}
                        {row.decision_note && ` · ${row.decision_note}`}
                    </p>
                )}
                {row.reviewed && (
                    <p>
                        Ditinjau {row.reviewed_by_name ?? ''} ·{' '}
                        {day(row.reviewed_at)}
                        {row.review_note && ` · ${row.review_note}`}
                    </p>
                )}
            </div>

            {(row.can_approve ||
                row.can_reject ||
                row.can_cancel ||
                row.can_review) && (
                <div className="flex flex-wrap gap-2 pt-1">
                    {row.can_approve && (
                        <Button type="button" size="sm" onClick={onApprove}>
                            <Check />
                            Setujui
                        </Button>
                    )}
                    {row.can_reject && (
                        <ConfirmAction
                            trigger={
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                >
                                    Tolak
                                </Button>
                            }
                            title={`Tolak mutasi ${row.name}?`}
                            description="Santri tetap di kamarnya sekarang."
                            action={`/asrama/mutasi/${row.id}/tolak`}
                            confirmLabel="Ya, tolak"
                            reason={{
                                label: 'Alasan penolakan',
                                max: REASON_MAX,
                                required: true,
                            }}
                            errorBag={`rejectMutation-${row.id}`}
                        />
                    )}
                    {row.can_cancel && (
                        <ConfirmAction
                            trigger={
                                <Button type="button" size="sm" variant="ghost">
                                    Tarik pengajuan
                                </Button>
                            }
                            title="Tarik pengajuan mutasi ini?"
                            description="Pengajuan dibatalkan dan tetap tercatat di riwayat."
                            action={`/asrama/mutasi/${row.id}/tarik`}
                            confirmLabel="Ya, tarik"
                            errorBag={`cancelMutation-${row.id}`}
                        />
                    )}
                    {row.can_review && (
                        <ConfirmAction
                            trigger={
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                >
                                    <Check />
                                    Sudah ditinjau
                                </Button>
                            }
                            title="Tandai mutasi darurat sudah ditinjau?"
                            description="Pastikan kategori dan alasannya wajar."
                            action={`/asrama/mutasi/${row.id}/tinjau`}
                            confirmLabel="Tandai ditinjau"
                            destructive={false}
                            reason={{
                                name: 'note',
                                label: 'Catatan (opsional)',
                                max: noteMax,
                                required: false,
                            }}
                            errorBag={`reviewMutation-${row.id}`}
                        />
                    )}
                </div>
            )}
        </li>
    );
}

/** Setujui = langsung dilaksanakan; pilih bed/loker di kamar tujuan (kosong = otomatis). */
function ApproveDialog({
    row,
    onClose,
}: {
    row: MutationRow;
    onClose: () => void;
}) {
    const resources = row.resources;
    /** Pilihan bed/loker: kosong = otomatis (saran server) bila diwajibkan dasar kapasitas, atau "tanpa". */
    const options = (
        list: Resource[],
        required: boolean,
        suggested: string | null,
        noun: string,
    ) => {
        const suggestion = list.find((item) => item.id === suggested)?.code;
        const empty = required
            ? suggestion
                ? `Otomatis (${suggestion})`
                : 'Otomatis'
            : `Tanpa ${noun}`;

        return [
            { value: '', label: empty },
            ...list.map((item) => ({ value: item.id, label: item.code })),
        ];
    };
    const [bed, setBed] = useState('');
    const [locker, setLocker] = useState('');

    return (
        <FacilityDialog
            open
            onClose={onClose}
            title={`Setujui mutasi ${row.name}`}
            description={`Langsung dilaksanakan hari ini: ${row.from_room} → ${row.to_room} (${row.to_dormitory}). Aturan kamar tujuan diperiksa ulang.`}
            action={`/asrama/mutasi/${row.id}/setujui`}
            errorBag="approveMutation"
            submitLabel="Setujui & pindahkan"
        >
            {(errors) =>
                resources && (
                    <div className="grid gap-4 sm:grid-cols-2">
                        {(resources.basis !== 'LOKER' ||
                            resources.beds.length > 0) && (
                            <Field
                                id="dm-approve-bed"
                                label="Bed"
                                error={errors.bed_id}
                            >
                                <Select
                                    id="dm-approve-bed"
                                    name="bed_id"
                                    value={bed}
                                    onChange={setBed}
                                    options={options(
                                        resources.beds,
                                        resources.basis !== 'LOKER',
                                        resources.suggestion.bed,
                                        'bed',
                                    )}
                                />
                            </Field>
                        )}
                        {(resources.basis !== 'BED' ||
                            resources.lockers.length > 0) && (
                            <Field
                                id="dm-approve-locker"
                                label="Loker"
                                error={errors.locker_id}
                            >
                                <Select
                                    id="dm-approve-locker"
                                    name="locker_id"
                                    value={locker}
                                    onChange={setLocker}
                                    options={options(
                                        resources.lockers,
                                        resources.basis !== 'BED',
                                        resources.suggestion.locker,
                                        'loker',
                                    )}
                                />
                            </Field>
                        )}
                    </div>
                )
            }
        </FacilityDialog>
    );
}
