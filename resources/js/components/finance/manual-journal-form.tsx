import { router } from '@inertiajs/react';
import { Plus, X } from 'lucide-react';
import { useState } from 'react';
import { formatRupiah, selectClass } from '@/components/finance/ledger';
import type { Option } from '@/components/finance/ledger';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export type ManualJournalOptions = {
    accounts: Option[];
    organizations: Option[];
    min_date: string;
    max_date: string;
    today: string;
    max_lines: number;
    description_max: number;
};

type Line = {
    key: number;
    account_id: string;
    organization_id: string;
    debit: string;
    credit: string;
};

/** "1.250.000" / "1250000" → 1250000; selain angka bulat → 0. */
const amount = (value: string) => {
    const digits = value.replace(/[.\s]/g, '').replace(/^rp/i, '');

    return /^\d{1,12}$/.test(digits) ? Number(digits) : 0;
};

let nextKey = 1;

const emptyLine = (): Line => ({
    key: nextKey++,
    account_id: '',
    organization_id: '',
    debit: '',
    credit: '',
});

/**
 * Formulir jurnal manual (PRD-007 §6, PB-2a): baris dinamis akun–lembaga–debit/kredit; total debit & kredit terlihat
 * langsung, tombol simpan aktif bila seimbang. Pemeriksaan akhir tetap di server.
 */
export function ManualJournalForm({
    options,
    onDone,
}: {
    options: ManualJournalOptions;
    onDone: () => void;
}) {
    const [date, setDate] = useState(options.today);
    const [description, setDescription] = useState('');
    const [lines, setLines] = useState<Line[]>([emptyLine(), emptyLine()]);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    const debit = lines.reduce((sum, line) => sum + amount(line.debit), 0);
    const credit = lines.reduce((sum, line) => sum + amount(line.credit), 0);
    const balanced = debit > 0 && debit === credit;

    const update = (
        key: number,
        field: keyof Omit<Line, 'key'>,
        value: string,
    ) =>
        setLines((current) =>
            current.map((line) =>
                line.key === key
                    ? {
                          ...line,
                          [field]: value,
                          // Satu baris hanya debit ATAU kredit.
                          ...(field === 'debit' && value !== ''
                              ? { credit: '' }
                              : {}),
                          ...(field === 'credit' && value !== ''
                              ? { debit: '' }
                              : {}),
                      }
                    : line,
            ),
        );

    const submit = () =>
        router.post(
            '/keuangan/pembukuan/jurnal',
            {
                date,
                description,
                lines: lines.map(
                    ({ account_id, organization_id, debit: d, credit: c }) => ({
                        account_id,
                        organization_id,
                        debit: d,
                        credit: c,
                    }),
                ),
            },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onError: (next) => setErrors(next as Record<string, string>),
                onSuccess: () => {
                    setErrors({});
                    onDone();
                },
            },
        );

    return (
        <form
            className="grid gap-3"
            onSubmit={(event) => {
                event.preventDefault();
                submit();
            }}
        >
            <div className="grid gap-3 sm:grid-cols-[12rem_1fr]">
                <div className="grid gap-1.5">
                    <Label htmlFor="mj-date">Tanggal</Label>
                    <Input
                        id="mj-date"
                        type="date"
                        value={date}
                        min={options.min_date}
                        max={options.max_date}
                        onChange={(event) => setDate(event.target.value)}
                        required
                    />
                    <InputError message={errors.date} />
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor="mj-description">Uraian</Label>
                    <Input
                        id="mj-description"
                        value={description}
                        maxLength={options.description_max}
                        placeholder="mis. Penyusutan peralatan Oktober 2026"
                        onChange={(event) => setDescription(event.target.value)}
                        required
                    />
                    <InputError message={errors.description} />
                </div>
            </div>

            <ul className="space-y-3">
                {lines.map((line, index) => (
                    <li
                        key={line.key}
                        className="grid gap-2 rounded-lg border p-3"
                    >
                        <div className="flex items-center justify-between">
                            <span className="text-xs font-medium text-muted-foreground">
                                Baris {index + 1}
                            </span>
                            {lines.length > 2 && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    aria-label={`Hapus baris ${index + 1}`}
                                    onClick={() =>
                                        setLines((current) =>
                                            current.filter(
                                                (item) => item.key !== line.key,
                                            ),
                                        )
                                    }
                                >
                                    <X />
                                </Button>
                            )}
                        </div>
                        <div className="grid gap-2 sm:grid-cols-2">
                            <select
                                aria-label={`Akun baris ${index + 1}`}
                                className={selectClass}
                                value={line.account_id}
                                onChange={(event) =>
                                    update(
                                        line.key,
                                        'account_id',
                                        event.target.value,
                                    )
                                }
                            >
                                <option value="">Pilih akun</option>
                                {options.accounts.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                            <select
                                aria-label={`Lembaga baris ${index + 1}`}
                                className={selectClass}
                                value={line.organization_id}
                                onChange={(event) =>
                                    update(
                                        line.key,
                                        'organization_id',
                                        event.target.value,
                                    )
                                }
                            >
                                {options.organizations.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                            <Input
                                aria-label={`Debit baris ${index + 1}`}
                                inputMode="numeric"
                                placeholder="Debit"
                                value={line.debit}
                                onChange={(event) =>
                                    update(
                                        line.key,
                                        'debit',
                                        event.target.value,
                                    )
                                }
                            />
                            <Input
                                aria-label={`Kredit baris ${index + 1}`}
                                inputMode="numeric"
                                placeholder="Kredit"
                                value={line.credit}
                                onChange={(event) =>
                                    update(
                                        line.key,
                                        'credit',
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                    </li>
                ))}
            </ul>

            {lines.length < options.max_lines && (
                <Button
                    type="button"
                    variant="outline"
                    onClick={() =>
                        setLines((current) => [...current, emptyLine()])
                    }
                >
                    <Plus />
                    Tambah baris
                </Button>
            )}

            <div
                className={`flex flex-wrap justify-between gap-2 rounded-lg p-3 text-sm ${balanced ? 'bg-muted/50' : 'bg-amber-50 text-amber-950 dark:bg-amber-950 dark:text-amber-100'}`}
                aria-live="polite"
            >
                <span>
                    Debit{' '}
                    <strong className="tabular-nums">
                        {formatRupiah(debit)}
                    </strong>
                </span>
                <span>
                    Kredit{' '}
                    <strong className="tabular-nums">
                        {formatRupiah(credit)}
                    </strong>
                </span>
                {!balanced && debit + credit > 0 && (
                    <span className="w-full text-xs">
                        Selisih {formatRupiah(Math.abs(debit - credit))}: total
                        debit harus sama dengan total kredit.
                    </span>
                )}
            </div>

            <InputError message={errors.lines ?? errors.form} />
            <p className="text-xs text-muted-foreground">
                Akun kas/bank tidak bisa dipakai: uang masuk/keluar dicatat di
                Kas. Jurnal manual tidak bisa diubah; bila salah, batalkan lalu
                catat ulang.
            </p>
            <Button type="submit" disabled={processing || !balanced}>
                Simpan jurnal
            </Button>
        </form>
    );
}
