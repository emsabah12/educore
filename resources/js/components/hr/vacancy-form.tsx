import { Form } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { selectClass } from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/*
 * Form lowongan (PRD-001 §20.1, HR-9a), dipakai di daftar (tambah) dan detail lowongan (ubah) — selalu di pop-up.
 */

export type Option = { value: string; label: string };

export type Vacancy = {
    id: string;
    title: string;
    organization_id: string;
    organization: string;
    position_id: string;
    position: string;
    openings: number;
    closes_on: string | null;
    requirements: string | null;
    status: 'DRAF' | 'DIBUKA' | 'DITUTUP';
    status_label: string;
    counts: Record<string, number>;
    total: number;
};

export type RecruitmentLimits = {
    title: number;
    requirements: number;
    openings: number;
    name: number;
    email: number;
    major: number;
    note: number;
    reason: number;
    fileMaxMb: number;
};

export const textareaClass =
    'min-h-20 w-full rounded-md border border-input bg-transparent px-3.5 py-2.5 text-base outline-none transition-colors placeholder:text-muted-foreground hover:border-foreground/60 focus-visible:border-primary focus-visible:ring-1 focus-visible:ring-primary md:text-sm';

const dateFormat = new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium' });

export function formatRecruitmentDate(value: string): string {
    const [year, month, day] = value.slice(0, 10).split('-').map(Number);

    return dateFormat.format(new Date(year, month - 1, day));
}

export function VacancyForm({
    vacancy,
    organizations,
    positions,
    limits,
    onDone,
}: {
    vacancy?: Vacancy;
    organizations: Option[];
    positions: Option[];
    limits: RecruitmentLimits;
    onDone: () => void;
}) {
    const key = vacancy?.id ?? 'new';

    return (
        <Form
            action={
                vacancy
                    ? `/pegawai/rekrutmen/${vacancy.id}`
                    : '/pegawai/rekrutmen'
            }
            method={vacancy ? 'patch' : 'post'}
            errorBag={`vacancy-${key}`}
            options={{ preserveScroll: true }}
            onSuccess={onDone}
            className="space-y-3"
        >
            {({ processing, errors }) => (
                <>
                    <div className="grid gap-1.5">
                        <Label htmlFor={`vacancy-title-${key}`}>
                            Judul lowongan
                        </Label>
                        <Input
                            id={`vacancy-title-${key}`}
                            name="title"
                            defaultValue={vacancy?.title ?? ''}
                            maxLength={limits.title}
                            placeholder="mis. Guru Matematika MA"
                            required
                        />
                        <InputError message={errors.title} />
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="grid gap-1.5">
                            <Label htmlFor={`vacancy-org-${key}`}>
                                Lembaga tujuan
                            </Label>
                            <select
                                id={`vacancy-org-${key}`}
                                name="organization_id"
                                className={selectClass}
                                defaultValue={vacancy?.organization_id ?? ''}
                                required
                            >
                                <option value="">Pilih lembaga</option>
                                {organizations.map((item) => (
                                    <option key={item.value} value={item.value}>
                                        {item.label}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.organization_id} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor={`vacancy-position-${key}`}>
                                Jabatan
                            </Label>
                            <select
                                id={`vacancy-position-${key}`}
                                name="position_id"
                                className={selectClass}
                                defaultValue={vacancy?.position_id ?? ''}
                                required
                            >
                                <option value="">Pilih jabatan</option>
                                {positions.map((item) => (
                                    <option key={item.value} value={item.value}>
                                        {item.label}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.position_id} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor={`vacancy-openings-${key}`}>
                                Jumlah dibutuhkan
                            </Label>
                            <Input
                                id={`vacancy-openings-${key}`}
                                name="openings"
                                type="number"
                                inputMode="numeric"
                                min={1}
                                max={limits.openings}
                                defaultValue={vacancy?.openings ?? 1}
                                required
                            />
                            <InputError message={errors.openings} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor={`vacancy-closes-${key}`}>
                                Batas lamaran (opsional)
                            </Label>
                            <Input
                                id={`vacancy-closes-${key}`}
                                name="closes_on"
                                type="date"
                                defaultValue={vacancy?.closes_on ?? ''}
                            />
                            <InputError message={errors.closes_on} />
                        </div>
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor={`vacancy-requirements-${key}`}>
                            Persyaratan (opsional)
                        </Label>
                        <textarea
                            id={`vacancy-requirements-${key}`}
                            name="requirements"
                            className={textareaClass}
                            maxLength={limits.requirements}
                            defaultValue={vacancy?.requirements ?? ''}
                            placeholder="mis. S1 Pendidikan Matematika, bisa membaca kitab kuning"
                        />
                        <InputError message={errors.requirements} />
                    </div>
                    <InputError
                        message={(errors as Record<string, string>).form}
                    />
                    <div className="flex flex-wrap gap-2">
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Menyimpan…' : 'Simpan lowongan'}
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onDone}
                        >
                            Batal
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

export function VacancyStatusBadge({
    status,
    label,
}: {
    status: string;
    label: string;
}) {
    if (status === 'DIBUKA') {
        return <Badge>{label}</Badge>;
    }

    return (
        <Badge variant={status === 'DRAF' ? 'outline' : 'secondary'}>
            {label}
        </Badge>
    );
}
