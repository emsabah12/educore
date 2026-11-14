import { Head, Link, router } from '@inertiajs/react';
import { BedDouble, Building2, Plus } from 'lucide-react';
import { useState } from 'react';
import {
    CodeNameFields,
    Empty,
    FacilityDialog,
    Field,
    Select,
} from '@/components/dormitory/facility-forms';
import type { Option } from '@/components/dormitory/types';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { selectClass } from '@/components/native-select';

type DormitoryRow = {
    id: string;
    code: string;
    name: string;
    organization: string;
    organization_id: string;
    is_active: boolean;
    buildings: number;
    rooms: number;
    effective: number;
    resident_slots: number;
    supervisor_slots: number;
    can_manage: boolean;
};

type Props = {
    filters: { lembaga: string | null; status: 'aktif' | 'semua' };
    options: { owners: Option[]; createOwners: Option[]; policies: Option[] };
    dormitories: DormitoryRow[];
};

const BASE_URL = '/asrama';

/**
 * Daftar asrama di cakupan pengguna (PRD-008 §10, DM-1). Kapasitas = kamar aktif di gedung aktif, dihitung dari
 * bed/loker yang tersedia. Tambah asrama hanya bagi pemilik izin kelola fasilitas di lembaga/unit pemilik.
 */
export default function Dormitories({ filters, options, dormitories }: Props) {
    const [adding, setAdding] = useState(false);

    const visit = (next: Partial<Props['filters']>) =>
        router.get(
            BASE_URL,
            {
                ...(filters.lembaga ? { lembaga: filters.lembaga } : {}),
                ...(filters.status === 'semua' ? { status: 'semua' } : {}),
                ...next,
            },
            { preserveState: true, replace: true },
        );

    return (
        <>
            <Head title="Asrama" />

            <div className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-4 p-4">
                <Heading
                    title="Asrama"
                    description="Asrama, gedung, kamar, serta bed dan loker yang menentukan daya tampung."
                />

                <div className="grid grid-cols-[repeat(auto-fit,minmax(14rem,1fr))] items-end gap-3">
                    {options.owners.length > 1 && (
                        <div className="grid gap-1.5">
                            <Label htmlFor="dm-owner">Pemilik</Label>
                            <select
                                id="dm-owner"
                                className={selectClass}
                                value={filters.lembaga ?? ''}
                                onChange={(event) =>
                                    visit({
                                        lembaga: event.target.value || null,
                                    })
                                }
                            >
                                <option value="">Semua</option>
                                {options.owners.map((owner) => (
                                    <option
                                        key={owner.value}
                                        value={owner.value}
                                    >
                                        {owner.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                    )}
                    <label className="flex min-h-10 items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            className="size-4 accent-primary"
                            checked={filters.status === 'semua'}
                            onChange={(event) =>
                                visit({
                                    status: event.target.checked
                                        ? 'semua'
                                        : 'aktif',
                                })
                            }
                        />
                        Tampilkan yang nonaktif
                    </label>
                    {options.createOwners.length > 0 && (
                        <Button
                            type="button"
                            onClick={() => setAdding(true)}
                            className="w-full sm:w-auto sm:justify-self-end"
                        >
                            <Plus />
                            Tambah asrama
                        </Button>
                    )}
                </div>

                {dormitories.length === 0 ? (
                    <Empty>
                        {options.createOwners.length > 0
                            ? 'Belum ada asrama. Mulai dengan menambah asrama, lalu gedung dan kamarnya.'
                            : 'Belum ada asrama yang bisa Anda lihat dari lembaga kerja ini.'}
                    </Empty>
                ) : (
                    <ul className="grid gap-3 sm:grid-cols-2">
                        {dormitories.map((dormitory) => (
                            <li key={dormitory.id}>
                                <Link
                                    href={`${BASE_URL}/${dormitory.id}`}
                                    className="block h-full rounded-xl border p-4 transition-colors hover:border-primary focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                >
                                    <div className="flex flex-wrap items-start justify-between gap-2">
                                        <div className="min-w-0">
                                            <p className="font-medium break-words">
                                                {dormitory.name}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {dormitory.code} ·{' '}
                                                {dormitory.organization}
                                            </p>
                                        </div>
                                        {!dormitory.is_active && (
                                            <Badge variant="outline">
                                                Nonaktif
                                            </Badge>
                                        )}
                                    </div>
                                    <div className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-sm text-muted-foreground">
                                        <span className="inline-flex items-center gap-1">
                                            <Building2 className="size-4" />
                                            {dormitory.buildings} gedung ·{' '}
                                            {dormitory.rooms} kamar
                                        </span>
                                        <span className="inline-flex items-center gap-1">
                                            <BedDouble className="size-4" />
                                            {dormitory.effective === 0
                                                ? 'Belum ada kapasitas'
                                                : `${dormitory.effective} tempat (${dormitory.resident_slots} santri${dormitory.supervisor_slots > 0 ? ` + ${dormitory.supervisor_slots} pengawas` : ''})`}
                                        </span>
                                    </div>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            <FacilityDialog
                open={adding}
                onClose={() => setAdding(false)}
                title="Tambah asrama"
                description="Asrama dimiliki satu lembaga atau unit. Pemilik tidak bisa diganti setelah disimpan."
                action={BASE_URL}
                errorBag="createDormitory"
                submitLabel="Simpan asrama"
            >
                {(errors) => (
                    <>
                        <Field
                            id="dm-new-owner"
                            label="Pemilik"
                            error={errors.organization_id}
                            required
                        >
                            <Select
                                id="dm-new-owner"
                                name="organization_id"
                                options={options.createOwners}
                                defaultValue={
                                    options.createOwners.length === 1
                                        ? options.createOwners[0].value
                                        : ''
                                }
                                placeholder="Pilih lembaga/unit"
                            />
                        </Field>
                        <CodeNameFields
                            prefix="dm-new"
                            errors={errors}
                            nameLabel="Nama asrama"
                            codeHint="Mis. ASR-PA-U1"
                        />
                        <Field
                            id="dm-new-policy"
                            label="Akhir tahun ajaran (bawaan kamar baru)"
                            error={errors.default_year_end_policy}
                        >
                            <Select
                                id="dm-new-policy"
                                name="default_year_end_policy"
                                options={options.policies}
                                defaultValue="TETAP"
                            />
                        </Field>
                    </>
                )}
            </FacilityDialog>
        </>
    );
}
