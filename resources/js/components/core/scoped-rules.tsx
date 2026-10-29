import { Lock } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Label } from '@/components/ui/label';

/*
 * Bagian form & daftar aturan berjenjang (PRD-000 §8) — Pengaturan gaji (HR-5b), Pengaturan keuangan (FN-2a): target
 * se-yayasan, satu jenjang, atau lembaga/unit; daftar aturan tersimpan & nilai yang berlaku per lembaga.
 */

type Option = { value: string; label: string };

export type TargetOptions = { jenjang: Option[]; organizations: Option[] };

export type RulesData = {
    rules: { id: string; target: string; days: string; enforced: boolean }[];
    usage: { id: string; name: string; days: string; source: string }[];
};

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';

export function TargetFields({
    idPrefix,
    options,
    errors,
}: {
    idPrefix: string;
    options: TargetOptions;
    errors: Record<string, string | undefined>;
}) {
    const [target, setTarget] = useState<'yayasan' | 'jenjang' | 'lembaga'>(
        'yayasan',
    );

    return (
        <>
            <div
                role="radiogroup"
                aria-label="Berlaku untuk"
                className="grid grid-cols-[repeat(auto-fit,minmax(9rem,1fr))] gap-2"
            >
                {(
                    [
                        ['yayasan', 'Seluruh yayasan'],
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
            {target === 'jenjang' && (
                <div className="grid gap-1.5">
                    <Label htmlFor={`${idPrefix}-jenjang`}>Jenjang</Label>
                    <select
                        id={`${idPrefix}-jenjang`}
                        name="jenjang"
                        className={selectClass}
                        defaultValue=""
                        required
                    >
                        <option value="" disabled>
                            Pilih jenjang
                        </option>
                        {options.jenjang.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </select>
                    <InputError message={errors.jenjang} />
                </div>
            )}
            {target === 'lembaga' && (
                <div className="grid gap-1.5">
                    <Label htmlFor={`${idPrefix}-organization`}>
                        Lembaga / unit
                    </Label>
                    <select
                        id={`${idPrefix}-organization`}
                        name="organization_id"
                        className={selectClass}
                        defaultValue=""
                        required
                    >
                        <option value="" disabled>
                            Pilih lembaga atau unit
                        </option>
                        {options.organizations.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </select>
                    <InputError message={errors.organization_id} />
                </div>
            )}
        </>
    );
}

export function EnforcedCheckbox({ text }: { text: string }) {
    return (
        <label className="flex cursor-pointer items-start gap-2 text-sm">
            <input
                type="checkbox"
                name="enforced"
                value="1"
                className="mt-0.5 size-4 accent-primary"
            />
            {text}
        </label>
    );
}

export function RulesPanel({
    data,
    emptyText,
    usageTitle,
}: {
    data: RulesData;
    emptyText: string;
    usageTitle: string;
}) {
    return (
        <div className="space-y-4">
            <div className="rounded-xl border">
                {data.rules.length === 0 ? (
                    <p className="p-4 text-sm text-muted-foreground">
                        {emptyText}
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
                                    {rule.days}
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
                        {usageTitle} ({data.usage.length})
                    </summary>
                    <ul className="divide-y border-t">
                        {data.usage.map((row) => (
                            <li
                                key={row.id}
                                className="flex flex-wrap items-center justify-between gap-2 px-4 py-2 text-sm"
                            >
                                <span className="break-words">{row.name}</span>
                                <span className="flex flex-wrap items-center gap-1.5">
                                    {row.days}
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
    );
}
