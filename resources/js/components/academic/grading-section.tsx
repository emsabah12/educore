import { Form } from '@inertiajs/react';
import { Copy, Lock, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { Option } from '@/components/academic/student-actions';
import { selectClass } from '@/components/academic/student-actions';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/*
 * Bagian "Aturan penilaian" di halaman Kalender akademik (PRD-002 §19 AC-5a): KKM, komponen nilai &
 * bobot, batas predikat per jenjang/lembaga. Hanya Admin Yayasan; pola sama dengan Mode presensi.
 * Semua aturan dicek server; jumlah bobot di sini hanya bantuan.
 */

type Component = { code: string; name: string; weight: number };

type RulesValue = {
    kkm: number;
    grade_a: number;
    grade_b: number;
    components: Component[];
};

export type Grading = {
    rules: {
        id: string;
        target: string;
        summary: string;
        enforced: boolean;
        value: RulesValue;
    }[];
    usage: { id: string; name: string; summary: string; source: string }[];
    defaults: RulesValue;
    maxComponents: number;
};

export function GradingSection({
    data,
    options,
}: {
    data: Grading;
    options: { jenjang: Option[]; organizations: Option[] };
}) {
    // Isi form bisa diambil dari aturan yang sudah ada ("Pakai sebagai dasar").
    const [base, setBase] = useState<{ key: string; value: RulesValue }>({
        key: 'default',
        value: data.defaults,
    });

    return (
        <section aria-label="Aturan penilaian" className="space-y-4">
            <Heading
                variant="small"
                title="Aturan penilaian"
                description="KKM, komponen nilai beserta bobotnya, dan batas predikat. Nilai akhir = jumlah (nilai komponen × bobot) ÷ 100. Bawaan: KKM 75, Nilai harian 40%, PTS 30%, PAS 30%."
            />

            <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                <div className="rounded-xl border p-4">
                    <GradingForm
                        key={base.key}
                        initial={base.value}
                        maxComponents={data.maxComponents}
                        options={options}
                    />
                </div>

                <div className="space-y-4">
                    <div className="rounded-xl border">
                        {data.rules.length === 0 ? (
                            <p className="p-4 text-sm text-muted-foreground">
                                Belum ada pengaturan. Semua lembaga memakai
                                aturan bawaan.
                            </p>
                        ) : (
                            <ul className="divide-y">
                                {data.rules.map((rule) => (
                                    <li
                                        key={rule.id}
                                        className="space-y-2 p-4 text-sm"
                                    >
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <span className="flex flex-wrap items-center gap-1.5 font-medium break-words">
                                                {rule.target}
                                                {rule.enforced && (
                                                    <Badge variant="outline">
                                                        <Lock />
                                                        Dikunci
                                                    </Badge>
                                                )}
                                            </span>
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    setBase({
                                                        key: `${rule.id}-${Date.now()}`,
                                                        value: rule.value,
                                                    })
                                                }
                                            >
                                                <Copy />
                                                Pakai sebagai dasar
                                            </Button>
                                        </div>
                                        <p className="break-words text-muted-foreground">
                                            {rule.summary}
                                        </p>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>

                    {data.usage.length > 0 && (
                        <details className="rounded-xl border">
                            <summary className="cursor-pointer p-4 text-sm font-medium">
                                Aturan yang berlaku di tiap lembaga (
                                {data.usage.length})
                            </summary>
                            <ul className="divide-y border-t">
                                {data.usage.map((row) => (
                                    <li
                                        key={row.id}
                                        className="space-y-1 px-4 py-2 text-sm"
                                    >
                                        <p className="flex flex-wrap items-center justify-between gap-2">
                                            <span className="font-medium break-words">
                                                {row.name}
                                            </span>
                                            <Badge variant="outline">
                                                {row.source}
                                            </Badge>
                                        </p>
                                        <p className="break-words text-muted-foreground">
                                            {row.summary}
                                        </p>
                                    </li>
                                ))}
                            </ul>
                        </details>
                    )}
                </div>
            </div>
        </section>
    );
}

function GradingForm({
    initial,
    maxComponents,
    options,
}: {
    initial: RulesValue;
    maxComponents: number;
    options: { jenjang: Option[]; organizations: Option[] };
}) {
    const [target, setTarget] = useState<'jenjang' | 'lembaga'>('jenjang');
    // Kunci baris tetap saat baris lain dihapus, agar isian tidak bergeser.
    const [rows, setRows] = useState<(Component & { key: number })[]>(() =>
        initial.components.map((component, index) => ({
            ...component,
            key: index,
        })),
    );
    const [nextKey, setNextKey] = useState(initial.components.length);
    const total = rows.reduce(
        (sum, row) => sum + (Number.isFinite(row.weight) ? row.weight : 0),
        0,
    );

    const update = (key: number, change: Partial<Component>) =>
        setRows((current) =>
            current.map((row) =>
                row.key === key ? { ...row, ...change } : row,
            ),
        );

    return (
        <Form
            action="/akademik/kalender/aturan-penilaian"
            method="post"
            errorBag="grading"
            options={{ preserveScroll: true }}
            className="space-y-3"
        >
            {({ processing, errors }) => (
                <>
                    <h3 className="text-sm font-medium">
                        Atur aturan penilaian
                    </h3>
                    <InputError message={errors.form} />

                    <div
                        role="radiogroup"
                        aria-label="Berlaku untuk"
                        className="grid grid-cols-[repeat(auto-fit,minmax(9rem,1fr))] gap-2"
                    >
                        {(
                            [
                                ['jenjang', 'Satu jenjang'],
                                ['lembaga', 'Lembaga / unit'],
                            ] as const
                        ).map(([value, label]) => (
                            <label
                                key={value}
                                className={
                                    'flex min-h-10 cursor-pointer items-center gap-2 rounded-md border px-3 text-sm ' +
                                    (target === value
                                        ? 'border-primary bg-primary/5'
                                        : 'hover:bg-muted')
                                }
                            >
                                <input
                                    type="radio"
                                    name="target"
                                    value={value}
                                    checked={target === value}
                                    onChange={() => setTarget(value)}
                                    className="size-4 accent-primary"
                                />
                                {label}
                            </label>
                        ))}
                    </div>

                    {target === 'jenjang' ? (
                        <div className="grid gap-1.5">
                            <Label htmlFor="grading-jenjang">Jenjang</Label>
                            <select
                                id="grading-jenjang"
                                name="jenjang"
                                className={selectClass}
                                defaultValue=""
                                required
                            >
                                <option value="" disabled>
                                    Pilih jenjang
                                </option>
                                {options.jenjang.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.jenjang} />
                        </div>
                    ) : (
                        <div className="grid gap-1.5">
                            <Label htmlFor="grading-organization">
                                Lembaga / unit
                            </Label>
                            <select
                                id="grading-organization"
                                name="organization_id"
                                className={selectClass}
                                defaultValue=""
                                required
                            >
                                <option value="" disabled>
                                    Pilih lembaga atau unit
                                </option>
                                {options.organizations.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.organization_id} />
                        </div>
                    )}

                    <div className="grid grid-cols-[repeat(auto-fit,minmax(7rem,1fr))] gap-2">
                        {(
                            [
                                ['kkm', 'KKM', initial.kkm],
                                ['grade_a', 'Batas A', initial.grade_a],
                                ['grade_b', 'Batas B', initial.grade_b],
                            ] as const
                        ).map(([name, label, value]) => (
                            <div key={name} className="grid gap-1.5">
                                <Label htmlFor={`grading-${name}`}>
                                    {label}
                                </Label>
                                <Input
                                    id={`grading-${name}`}
                                    name={name}
                                    type="number"
                                    min={0}
                                    max={100}
                                    step={1}
                                    defaultValue={value}
                                    required
                                />
                                <InputError message={errors[name]} />
                            </div>
                        ))}
                    </div>
                    <p className="text-xs text-muted-foreground">
                        Predikat: A ≥ batas A, B ≥ batas B, C ≥ KKM, D di bawah
                        KKM.
                    </p>

                    <fieldset className="space-y-2">
                        <legend className="text-sm font-medium">
                            Komponen nilai
                        </legend>
                        {rows.map((row, index) => (
                            <div
                                key={row.key}
                                className="grid grid-cols-[minmax(0,1fr)_5rem_auto] items-end gap-2 sm:grid-cols-[7rem_minmax(0,1fr)_5rem_auto]"
                            >
                                <div className="col-span-3 grid gap-1 sm:col-span-1">
                                    <Label
                                        htmlFor={`grading-code-${row.key}`}
                                        className="text-xs"
                                    >
                                        Kode
                                    </Label>
                                    <Input
                                        id={`grading-code-${row.key}`}
                                        name={`components[${index}][code]`}
                                        value={row.code}
                                        maxLength={10}
                                        onChange={(event) =>
                                            update(row.key, {
                                                code: event.target.value.toUpperCase(),
                                            })
                                        }
                                        required
                                    />
                                </div>
                                <div className="grid gap-1">
                                    <Label
                                        htmlFor={`grading-name-${row.key}`}
                                        className="text-xs"
                                    >
                                        Nama
                                    </Label>
                                    <Input
                                        id={`grading-name-${row.key}`}
                                        name={`components[${index}][name]`}
                                        value={row.name}
                                        maxLength={50}
                                        onChange={(event) =>
                                            update(row.key, {
                                                name: event.target.value,
                                            })
                                        }
                                        required
                                    />
                                </div>
                                <div className="grid gap-1">
                                    <Label
                                        htmlFor={`grading-weight-${row.key}`}
                                        className="text-xs"
                                    >
                                        Bobot %
                                    </Label>
                                    <Input
                                        id={`grading-weight-${row.key}`}
                                        name={`components[${index}][weight]`}
                                        type="number"
                                        min={1}
                                        max={100}
                                        step={1}
                                        value={
                                            Number.isFinite(row.weight)
                                                ? row.weight
                                                : ''
                                        }
                                        onChange={(event) =>
                                            update(row.key, {
                                                weight: Number.parseInt(
                                                    event.target.value,
                                                    10,
                                                ),
                                            })
                                        }
                                        required
                                    />
                                </div>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="icon"
                                    aria-label={`Hapus komponen ${row.name || index + 1}`}
                                    disabled={rows.length <= 1}
                                    onClick={() =>
                                        setRows((current) =>
                                            current.filter(
                                                (item) => item.key !== row.key,
                                            ),
                                        )
                                    }
                                >
                                    <Trash2 />
                                </Button>
                            </div>
                        ))}

                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                disabled={rows.length >= maxComponents}
                                onClick={() => {
                                    setRows((current) => [
                                        ...current,
                                        {
                                            key: nextKey,
                                            code: '',
                                            name: '',
                                            weight: 0,
                                        },
                                    ]);
                                    setNextKey((key) => key + 1);
                                }}
                            >
                                <Plus />
                                Tambah komponen
                            </Button>
                            <span
                                className={
                                    'text-sm font-medium tabular-nums ' +
                                    (total === 100
                                        ? 'text-emerald-700 dark:text-emerald-300'
                                        : 'text-red-600 dark:text-red-400')
                                }
                            >
                                Jumlah bobot: {total}%
                                {total !== 100 && ' (harus 100%)'}
                            </span>
                        </div>
                        <InputError
                            message={
                                errors.components ??
                                Object.entries(errors).find(([field]) =>
                                    field.startsWith('components.'),
                                )?.[1]
                            }
                        />
                    </fieldset>

                    <label className="flex cursor-pointer items-start gap-2 text-sm">
                        <input
                            type="checkbox"
                            name="enforced"
                            value="1"
                            className="mt-0.5 size-4 accent-primary"
                        />
                        Kunci: lembaga di bawahnya tidak bisa memakai aturan
                        lain.
                    </label>

                    <Button type="submit" disabled={processing}>
                        Simpan aturan penilaian
                    </Button>
                </>
            )}
        </Form>
    );
}
