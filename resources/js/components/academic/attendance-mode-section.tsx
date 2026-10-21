import { Form } from '@inertiajs/react';
import { Lock } from 'lucide-react';
import { useState } from 'react';
import type { Option } from '@/components/academic/student-actions';
import { selectClass } from '@/components/academic/student-actions';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

/*
 * Bagian "Mode presensi" di halaman Kalender akademik (PRD-002 §17 AC-4c). Hanya Admin Yayasan;
 * pola sama dengan Pemakaian kalender. Semua aturan dicek server.
 */

export type AttendanceModes = {
    rules: { id: string; target: string; mode: string; enforced: boolean }[];
    usage: { id: string; name: string; mode: string; source: string }[];
    modes: Option[];
};

export function AttendanceModeSection({
    data,
    options,
}: {
    data: AttendanceModes;
    options: { jenjang: Option[]; organizations: Option[] };
}) {
    return (
        <section aria-label="Mode presensi" className="space-y-4">
            <Heading
                variant="small"
                title="Mode presensi"
                description="Harian: wali kelas mengisi sekali sehari. Per pertemuan: guru pengampu mengisi tiap baris jadwal; presensi harian ditutup. Bawaan: harian."
            />

            <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                <div className="rounded-xl border p-4">
                    <ModeForm modes={data.modes} options={options} />
                </div>

                <div className="space-y-4">
                    <div className="rounded-xl border">
                        {data.rules.length === 0 ? (
                            <p className="p-4 text-sm text-muted-foreground">
                                Belum ada pengaturan. Semua lembaga memakai
                                presensi harian.
                            </p>
                        ) : (
                            <ul className="divide-y">
                                {data.rules.map((rule) => (
                                    <li
                                        key={rule.id}
                                        className="flex flex-wrap items-center justify-between gap-2 p-4 text-sm"
                                    >
                                        <span className="font-medium break-words">
                                            {rule.target}
                                        </span>
                                        <span className="flex flex-wrap items-center gap-1.5 text-muted-foreground">
                                            {rule.mode}
                                            {rule.enforced && (
                                                <Badge variant="outline">
                                                    <Lock />
                                                    Dikunci
                                                </Badge>
                                            )}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>

                    {data.usage.length > 0 && (
                        <details className="rounded-xl border">
                            <summary className="cursor-pointer p-4 text-sm font-medium">
                                Mode yang berlaku di tiap lembaga (
                                {data.usage.length})
                            </summary>
                            <ul className="divide-y border-t">
                                {data.usage.map((row) => (
                                    <li
                                        key={row.id}
                                        className="flex flex-wrap items-center justify-between gap-2 px-4 py-2 text-sm"
                                    >
                                        <span className="break-words">
                                            {row.name}
                                        </span>
                                        <span className="flex items-center gap-1.5">
                                            {row.mode}
                                            <Badge variant="outline">
                                                {row.source}
                                            </Badge>
                                        </span>
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

function ModeForm({
    modes,
    options,
}: {
    modes: Option[];
    options: { jenjang: Option[]; organizations: Option[] };
}) {
    const [target, setTarget] = useState<'jenjang' | 'lembaga'>('jenjang');

    return (
        <Form
            action="/akademik/kalender/mode-presensi"
            method="post"
            errorBag="attendanceMode"
            options={{ preserveScroll: true }}
            className="space-y-3"
        >
            {({ processing, errors }) => (
                <>
                    <h3 className="text-sm font-medium">Atur mode presensi</h3>

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
                    <InputError message={errors.target} />

                    {target === 'jenjang' ? (
                        <div className="grid gap-1.5">
                            <Label htmlFor="mode-jenjang">Jenjang</Label>
                            <select
                                id="mode-jenjang"
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
                            <Label htmlFor="mode-organization">
                                Lembaga / unit
                            </Label>
                            <select
                                id="mode-organization"
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

                    <div className="grid gap-1.5">
                        <Label htmlFor="mode-value">Mode</Label>
                        <select
                            id="mode-value"
                            name="mode"
                            className={selectClass}
                            defaultValue=""
                            required
                        >
                            <option value="" disabled>
                                Pilih mode
                            </option>
                            {modes.map((mode) => (
                                <option key={mode.value} value={mode.value}>
                                    {mode.label}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.mode} />
                    </div>

                    <label className="flex cursor-pointer items-start gap-2 text-sm">
                        <input
                            type="checkbox"
                            name="enforced"
                            value="1"
                            className="mt-0.5 size-4 accent-primary"
                        />
                        Kunci: lembaga di bawahnya tidak bisa memakai mode lain.
                    </label>

                    <Button type="submit" disabled={processing}>
                        Simpan mode presensi
                    </Button>
                </>
            )}
        </Form>
    );
}
