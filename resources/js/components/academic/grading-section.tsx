import { Form } from '@inertiajs/react';
import { Copy, Layers, Lock, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type {
    GradingComponent as Component,
    PredicateLevel,
    GradingRules as RulesValue,
} from '@/components/academic/grading';
import { DEFAULT_ASPECT_CODE } from '@/components/academic/grading';
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
 * bobot, skala predikat per jenjang/lembaga; aspek penilaian (mis. Tulis & Praktik) dengan komponen
 * masing-masing (§22 AC-8a); skala predikat 2–6 tingkat bersebutan (AC-8b). Hanya Admin Yayasan; pola sama dengan Mode presensi. Semua aturan dicek
 * server; jumlah bobot di sini hanya bantuan.
 */

type ComponentRow = Component & { key: number };

type AspectRow = {
    key: number;
    code: string;
    name: string;
    rows: ComponentRow[];
};

type LevelRow = PredicateLevel & { key: number };

/** Isi cepat skala 5 tingkat seperti rapor madrasah. */
const FIVE_LEVELS: PredicateLevel[] = [
    { letter: 'A', label: 'Sangat Baik', min: 90 },
    { letter: 'B', label: 'Baik', min: 75 },
    { letter: 'C', label: 'Cukup Baik', min: 60 },
    { letter: 'D', label: 'Kurang Baik', min: 40 },
    { letter: 'E', label: 'Perlu Perbaikan', min: 0 },
];

/** Isi cepat dua aspek seperti rapor madrasah: ujian tulis & ujian praktik. */
const WRITTEN_AND_PRACTICE: Omit<AspectRow, 'key'>[] = [
    {
        code: 'TULIS',
        name: 'Pengetahuan & pemahaman konsep',
        rows: [
            { key: 0, code: 'HARIAN', name: 'Nilai harian', weight: 40 },
            { key: 1, code: 'PTS', name: 'Ujian tengah semester', weight: 30 },
            { key: 2, code: 'PAS', name: 'Ujian akhir semester', weight: 30 },
        ],
    },
    {
        code: 'PRAKTIK',
        name: 'Praktik / keterampilan',
        rows: [{ key: 3, code: 'PRAKTIK', name: 'Ujian praktik', weight: 100 }],
    },
];

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
    maxAspects: number;
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
                description="KKM, komponen nilai beserta bobotnya, dan batas predikat. Nilai = jumlah (nilai komponen × bobot) ÷ 100. Lembaga yang menilai dari beberapa sisi (mis. ujian tulis & praktik) bisa memakai aspek: tiap aspek punya komponen sendiri, nilai akhir = rata-rata aspek. Bawaan: KKM 75, Nilai harian 40%, PTS 30%, PAS 30%."
            />

            <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                <div className="rounded-xl border p-4">
                    <GradingForm
                        key={base.key}
                        initial={base.value}
                        maxComponents={data.maxComponents}
                        maxAspects={data.maxAspects}
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
    maxAspects,
    options,
}: {
    initial: RulesValue;
    maxComponents: number;
    maxAspects: number;
    options: { jenjang: Option[]; organizations: Option[] };
}) {
    const [target, setTarget] = useState<'jenjang' | 'lembaga'>('jenjang');
    // Kunci baris tetap saat baris lain dihapus, agar isian tidak bergeser.
    const [nextKey, setNextKey] = useState(1000);
    const [aspects, setAspects] = useState<AspectRow[]>(() => {
        let key = 0;

        return initial.aspects.map((aspect, index) => ({
            key: index,
            code: aspect.code,
            name: aspect.name,
            rows: aspect.components.map((component) => ({
                ...component,
                key: key++,
            })),
        }));
    });
    const multiple = aspects.length > 1;

    const newKey = () => {
        setNextKey((key) => key + 1);

        return nextKey;
    };

    const updateAspect = (key: number, change: Partial<AspectRow>) =>
        setAspects((current) =>
            current.map((aspect) =>
                aspect.key === key ? { ...aspect, ...change } : aspect,
            ),
        );

    const addAspect = () => {
        const key = newKey();

        setAspects((current) => [
            // Aspek bawaan "Nilai" diberi nama agar bisa dibedakan.
            ...current.map((aspect) =>
                aspect.code === DEFAULT_ASPECT_CODE && current.length === 1
                    ? { ...aspect, code: 'TULIS', name: 'Pengetahuan' }
                    : aspect,
            ),
            {
                key,
                code: '',
                name: '',
                rows: [
                    {
                        key: key + 500,
                        code: '',
                        name: '',
                        weight: 100,
                    },
                ],
            },
        ]);
        setNextKey((value) => value + 1);
    };

    const fillWrittenAndPractice = () => {
        setAspects(
            WRITTEN_AND_PRACTICE.map((aspect, index) => ({
                ...aspect,
                key: nextKey + index,
                rows: aspect.rows.map((row) => ({
                    ...row,
                    key: nextKey + 10 + row.key,
                })),
            })),
        );
        setNextKey((key) => key + 20);
    };

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
                                        ? 'border-primary bg-accent'
                                        : 'hover:bg-muted')
                                }
                            >
                                <input
                                    type="radio"
                                    name="target"
                                    value={value}
                                    checked={target === value}
                                    onChange={() => setTarget(value)}
                                    className="size-4 accent-foreground"
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

                    <div className="grid gap-1.5 sm:max-w-40">
                        <Label htmlFor="grading-kkm">KKM</Label>
                        <Input
                            id="grading-kkm"
                            name="kkm"
                            type="number"
                            min={0}
                            max={100}
                            step={1}
                            defaultValue={initial.kkm}
                            required
                        />
                        <InputError message={errors.kkm} />
                    </div>

                    <PredicateFields
                        initial={initial.predicates}
                        error={
                            errors.predicates ??
                            Object.entries(errors).find(([field]) =>
                                field.startsWith('predicates.'),
                            )?.[1]
                        }
                    />

                    <div className="space-y-3">
                        {aspects.map((aspect, aspectIndex) => (
                            <AspectFields
                                key={aspect.key}
                                aspect={aspect}
                                index={aspectIndex}
                                multiple={multiple}
                                maxComponents={maxComponents}
                                newKey={newKey}
                                onChange={(change) =>
                                    updateAspect(aspect.key, change)
                                }
                                onRemove={() =>
                                    setAspects((current) =>
                                        current.filter(
                                            (item) => item.key !== aspect.key,
                                        ),
                                    )
                                }
                            />
                        ))}

                        <div className="grid grid-cols-[repeat(auto-fit,minmax(11rem,1fr))] gap-2 sm:flex sm:flex-wrap">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                disabled={aspects.length >= maxAspects}
                                onClick={addAspect}
                            >
                                <Layers />
                                Tambah aspek
                            </Button>
                            {!multiple && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={fillWrittenAndPractice}
                                >
                                    Isi contoh: Tulis + Praktik
                                </Button>
                            )}
                        </div>
                        {multiple && (
                            <p className="text-xs text-muted-foreground">
                                Nilai akhir mapel = rata-rata nilai aspek yang
                                dinilai pada mapel itu. Aspek per mapel diatur
                                di menu Mata pelajaran.
                            </p>
                        )}
                        <InputError
                            message={
                                errors.aspects ??
                                errors.components ??
                                Object.entries(errors).find(
                                    ([field]) =>
                                        field.startsWith('aspects.') ||
                                        field.startsWith('components.'),
                                )?.[1]
                            }
                        />
                    </div>

                    <label className="flex cursor-pointer items-start gap-2 text-sm">
                        <input
                            type="checkbox"
                            name="enforced"
                            value="1"
                            className="mt-0.5 size-4 accent-foreground"
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

/** Satu aspek: kode & nama (hanya bila lebih dari satu aspek) dan daftar komponennya. */
function AspectFields({
    aspect,
    index,
    multiple,
    maxComponents,
    newKey,
    onChange,
    onRemove,
}: {
    aspect: AspectRow;
    index: number;
    multiple: boolean;
    maxComponents: number;
    newKey: () => number;
    onChange: (change: Partial<AspectRow>) => void;
    onRemove: () => void;
}) {
    const { rows } = aspect;
    const prefix = `aspects[${index}]`;
    const total = rows.reduce(
        (sum, row) => sum + (Number.isFinite(row.weight) ? row.weight : 0),
        0,
    );

    const update = (key: number, change: Partial<Component>) =>
        onChange({
            rows: rows.map((row) =>
                row.key === key ? { ...row, ...change } : row,
            ),
        });

    return (
        <fieldset
            className={
                'space-y-2 ' + (multiple ? 'rounded-lg bg-muted/40 p-3' : '')
            }
        >
            {multiple ? (
                <>
                    <legend className="sr-only">Aspek {index + 1}</legend>
                    <div className="grid grid-cols-[minmax(0,1fr)_auto] items-end gap-2 sm:grid-cols-[7rem_minmax(0,1fr)_auto]">
                        <div className="col-span-2 grid gap-1 sm:col-span-1">
                            <Label
                                htmlFor={`aspect-code-${aspect.key}`}
                                className="text-xs"
                            >
                                Kode aspek
                            </Label>
                            <Input
                                id={`aspect-code-${aspect.key}`}
                                name={`${prefix}[code]`}
                                value={aspect.code}
                                maxLength={10}
                                onChange={(event) =>
                                    onChange({
                                        code: event.target.value.toUpperCase(),
                                    })
                                }
                                required
                            />
                        </div>
                        <div className="grid gap-1">
                            <Label
                                htmlFor={`aspect-name-${aspect.key}`}
                                className="text-xs"
                            >
                                Nama aspek (judul kolom rapor)
                            </Label>
                            <Input
                                id={`aspect-name-${aspect.key}`}
                                name={`${prefix}[name]`}
                                value={aspect.name}
                                maxLength={50}
                                onChange={(event) =>
                                    onChange({ name: event.target.value })
                                }
                                required
                            />
                        </div>
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            aria-label={`Hapus aspek ${aspect.name || index + 1}`}
                            onClick={onRemove}
                        >
                            <Trash2 />
                        </Button>
                    </div>
                </>
            ) : (
                <>
                    <legend className="text-sm font-medium">
                        Komponen nilai
                    </legend>
                    <input
                        type="hidden"
                        name={`${prefix}[code]`}
                        value={aspect.code}
                    />
                    <input
                        type="hidden"
                        name={`${prefix}[name]`}
                        value={aspect.name}
                    />
                </>
            )}

            {rows.map((row, rowIndex) => (
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
                            name={`${prefix}[components][${rowIndex}][code]`}
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
                            name={`${prefix}[components][${rowIndex}][name]`}
                            value={row.name}
                            maxLength={50}
                            onChange={(event) =>
                                update(row.key, { name: event.target.value })
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
                            name={`${prefix}[components][${rowIndex}][weight]`}
                            type="number"
                            min={1}
                            max={100}
                            step={1}
                            value={
                                Number.isFinite(row.weight) ? row.weight : ''
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
                        aria-label={`Hapus komponen ${row.name || rowIndex + 1}`}
                        disabled={rows.length <= 1}
                        onClick={() =>
                            onChange({
                                rows: rows.filter(
                                    (item) => item.key !== row.key,
                                ),
                            })
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
                    onClick={() =>
                        onChange({
                            rows: [
                                ...rows,
                                {
                                    key: newKey(),
                                    code: '',
                                    name: '',
                                    weight: 0,
                                },
                            ],
                        })
                    }
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
                    Jumlah bobot
                    {multiple && aspect.name ? ` ${aspect.name}` : ''}: {total}%
                    {total !== 100 && ' (harus 100%)'}
                </span>
            </div>
        </fieldset>
    );
}

/**
 * Skala predikat: 2–6 tingkat urut dari tertinggi (huruf, sebutan, nilai minimal). Tingkat terakhir
 * selalu mulai dari 0 (kolomnya dikunci).
 */
function PredicateFields({
    initial,
    error,
}: {
    initial: PredicateLevel[];
    error?: string;
}) {
    const [levels, setLevels] = useState<LevelRow[]>(() =>
        initial.map((level, index) => ({ ...level, key: index })),
    );
    const [nextKey, setNextKey] = useState(initial.length + 100);

    const update = (key: number, change: Partial<PredicateLevel>) =>
        setLevels((current) =>
            current.map((level) =>
                level.key === key ? { ...level, ...change } : level,
            ),
        );

    return (
        <fieldset className="space-y-2">
            <legend className="text-sm font-medium">Skala predikat</legend>
            <p className="text-xs text-muted-foreground">
                Urut dari tertinggi. Predikat = tingkat tertinggi yang nilai
                minimalnya tercapai. Sebutan (mis. "Sangat Baik") tampil di
                rapor; kosongkan bila cukup huruf saja. Mapel bernilai huruf
                memilih dari huruf-huruf ini.
            </p>

            {levels.map((level, index) => {
                const last = index === levels.length - 1;

                return (
                    <div
                        key={level.key}
                        className="grid grid-cols-[4rem_minmax(0,1fr)_5rem_auto] items-end gap-2"
                    >
                        <div className="grid gap-1">
                            <Label
                                htmlFor={`level-letter-${level.key}`}
                                className="text-xs"
                            >
                                Huruf
                            </Label>
                            <Input
                                id={`level-letter-${level.key}`}
                                name={`predicates[${index}][letter]`}
                                value={level.letter}
                                maxLength={2}
                                onChange={(event) =>
                                    update(level.key, {
                                        letter: event.target.value.toUpperCase(),
                                    })
                                }
                                required
                            />
                        </div>
                        <div className="grid gap-1">
                            <Label
                                htmlFor={`level-label-${level.key}`}
                                className="text-xs"
                            >
                                Sebutan
                            </Label>
                            <Input
                                id={`level-label-${level.key}`}
                                name={`predicates[${index}][label]`}
                                value={level.label ?? ''}
                                maxLength={30}
                                placeholder="Opsional"
                                onChange={(event) =>
                                    update(level.key, {
                                        label: event.target.value,
                                    })
                                }
                            />
                        </div>
                        <div className="grid gap-1">
                            <Label
                                htmlFor={`level-min-${level.key}`}
                                className="text-xs"
                            >
                                Minimal
                            </Label>
                            <Input
                                id={`level-min-${level.key}`}
                                name={`predicates[${index}][min]`}
                                type="number"
                                min={0}
                                max={100}
                                step={1}
                                value={last ? 0 : level.min}
                                readOnly={last}
                                aria-readonly={last}
                                className={last ? 'bg-muted' : undefined}
                                onChange={(event) =>
                                    update(level.key, {
                                        min: Number.parseInt(
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
                            aria-label={`Hapus predikat ${level.letter || index + 1}`}
                            disabled={levels.length <= 2}
                            onClick={() =>
                                setLevels((current) =>
                                    current.filter(
                                        (item) => item.key !== level.key,
                                    ),
                                )
                            }
                        >
                            <Trash2 />
                        </Button>
                    </div>
                );
            })}

            <div className="grid grid-cols-[repeat(auto-fit,minmax(11rem,1fr))] gap-2 sm:flex sm:flex-wrap">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={levels.length >= 6}
                    onClick={() => {
                        setLevels((current) => [
                            ...current,
                            { key: nextKey, letter: '', label: null, min: 0 },
                        ]);
                        setNextKey((key) => key + 1);
                    }}
                >
                    <Plus />
                    Tambah tingkat
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={() => {
                        setLevels(
                            FIVE_LEVELS.map((level, index) => ({
                                ...level,
                                key: nextKey + index,
                            })),
                        );
                        setNextKey((key) => key + FIVE_LEVELS.length);
                    }}
                >
                    Isi contoh 5 tingkat
                </Button>
            </div>
            <InputError message={error} />
        </fieldset>
    );
}
