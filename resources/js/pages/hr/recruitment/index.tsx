import { Head, Link, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { FormDialog } from '@/components/form-dialog';
import Heading from '@/components/heading';
import type {
    Option,
    RecruitmentLimits,
    Vacancy,
} from '@/components/hr/vacancy-form';
import {
    VacancyForm,
    VacancyStatusBadge,
    formatRecruitmentDate,
} from '@/components/hr/vacancy-form';
import { selectClass } from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

type Props = {
    vacancies: Vacancy[];
    filters: { status: string | null; lembaga: string | null };
    canManage: boolean;
    options: {
        organizations: Option[];
        positions: Option[];
        statuses: Option[];
    };
    limits: RecruitmentLimits;
};

/**
 * Rekrutmen (PRD-001 §20.1, HR-9a): daftar lowongan dengan jumlah pelamar per tahap. Admin SDM / Admin Yayasan
 * membuat lowongan; Kepala Lembaga melihat lowongan untuk lembaganya.
 */
export default function RecruitmentIndex({
    vacancies,
    filters,
    canManage,
    options,
    limits,
}: Props) {
    const [adding, setAdding] = useState(false);

    const apply = (next: Partial<Props['filters']>) => {
        const merged = { ...filters, ...next };

        router.get(
            '/pegawai/rekrutmen',
            {
                status: merged.status ?? undefined,
                lembaga: merged.lembaga ?? undefined,
            },
            { preserveScroll: true, replace: true },
        );
    };

    return (
        <>
            <Head title="Rekrutmen" />

            <div className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading
                        title="Rekrutmen"
                        description="Lowongan dan pelamar. Seleksi: berkas → tes/wawancara → diterima atau tidak lolos."
                    />
                    {canManage && (
                        <Button
                            type="button"
                            onClick={() => setAdding(true)}
                            className="w-full sm:w-auto"
                        >
                            <Plus />
                            Tambah lowongan
                        </Button>
                    )}
                </div>

                {canManage && (
                    <FormDialog
                        open={adding}
                        onOpenChange={setAdding}
                        title="Tambah lowongan"
                        description="Lowongan dibuat sebagai Draf; buka lowongan untuk mulai mencatat pelamar."
                        wide
                    >
                        <VacancyForm
                            organizations={options.organizations}
                            positions={options.positions}
                            limits={limits}
                            onDone={() => setAdding(false)}
                        />
                    </FormDialog>
                )}

                <div className="grid gap-3 sm:grid-cols-2">
                    <div className="grid gap-1.5">
                        <Label htmlFor="filter-status">Status</Label>
                        <select
                            id="filter-status"
                            className={selectClass}
                            value={filters.status ?? ''}
                            onChange={(event) =>
                                apply({ status: event.target.value || null })
                            }
                        >
                            <option value="">Semua status</option>
                            {options.statuses.map((item) => (
                                <option key={item.value} value={item.value}>
                                    {item.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="filter-org">Lembaga</Label>
                        <select
                            id="filter-org"
                            className={selectClass}
                            value={filters.lembaga ?? ''}
                            onChange={(event) =>
                                apply({ lembaga: event.target.value || null })
                            }
                        >
                            <option value="">Semua lembaga</option>
                            {options.organizations.map((item) => (
                                <option key={item.value} value={item.value}>
                                    {item.label}
                                </option>
                            ))}
                        </select>
                    </div>
                </div>

                {vacancies.length === 0 ? (
                    <div className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                        {canManage
                            ? 'Belum ada lowongan. Tekan Tambah lowongan untuk membuat yang pertama.'
                            : 'Belum ada lowongan untuk lembaga Anda.'}
                    </div>
                ) : (
                    <ul className="divide-y rounded-xl border">
                        {vacancies.map((vacancy) => (
                            <li key={vacancy.id}>
                                <Link
                                    href={`/pegawai/rekrutmen/${vacancy.id}`}
                                    className="flex flex-wrap items-start justify-between gap-2 p-4 text-sm hover:bg-muted/40"
                                >
                                    <div className="min-w-0 space-y-0.5">
                                        <p className="font-medium break-words">
                                            {vacancy.title}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {vacancy.organization} ·{' '}
                                            {vacancy.position} · dibutuhkan{' '}
                                            {vacancy.openings} orang
                                            {vacancy.closes_on &&
                                                ` · batas ${formatRecruitmentDate(vacancy.closes_on)}`}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {vacancy.total} pelamar · berkas{' '}
                                            {vacancy.counts.BERKAS ?? 0} · tes{' '}
                                            {vacancy.counts.TES ?? 0} · diterima{' '}
                                            {vacancy.counts.DITERIMA ?? 0}
                                        </p>
                                    </div>
                                    <VacancyStatusBadge
                                        status={vacancy.status}
                                        label={vacancy.status_label}
                                    />
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}
