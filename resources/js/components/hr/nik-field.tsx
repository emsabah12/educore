import { TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/**
 * Peringatan bila digit tanggal lahir di NIK (digit 7–12: DDMMYY, tanggal +40 untuk perempuan)
 * tidak cocok dengan isian. Sama dengan PersonNik::mismatch di server; hanya peringatan.
 */
export function nikMismatch(
    nik: string,
    birthDate: string,
    gender: string,
): string | null {
    const digits = nik.replace(/[\s.-]/g, '');

    if (!/^\d{16}$/.test(digits)) {
        return null;
    }

    const day = Number(digits.slice(6, 8));
    const female = day > 40;
    const problems: string[] = [];

    if (gender !== '' && female !== (gender === 'P')) {
        problems.push('jenis kelamin');
    }

    const parts = /^\d{2}(\d{2})-(\d{2})-(\d{2})$/.exec(birthDate);

    if (parts) {
        const expected =
            String(female ? day - 40 : day).padStart(2, '0') +
            digits.slice(8, 10) +
            digits.slice(10, 12);

        if (expected !== parts[3] + parts[2] + parts[1]) {
            problems.push('tanggal lahir');
        }
    }

    return problems.length === 0
        ? null
        : `NIK tidak cocok dengan ${problems.join(' dan ')}. Periksa kembali bila perlu.`;
}

/**
 * Kolom NIK (OD-HR-01 revisi 2026-10-23): 16 angka, spasi/titik boleh (dirapikan server).
 */
export function NikField({
    id,
    required,
    defaultValue = '',
    birthDate,
    gender,
    error,
    hint,
}: {
    id: string;
    required: boolean;
    defaultValue?: string;
    birthDate: string;
    gender: string;
    error?: string;
    hint?: string;
}) {
    const [value, setValue] = useState(defaultValue);
    const warning = nikMismatch(value, birthDate, gender);

    return (
        <div className="grid content-start gap-1.5">
            <Label htmlFor={id}>
                NIK
                {required && (
                    <span className="text-red-600 dark:text-red-400"> *</span>
                )}
            </Label>
            <Input
                id={id}
                name="nik"
                inputMode="numeric"
                autoComplete="off"
                maxLength={22}
                placeholder="16 angka sesuai KTP/KK"
                value={value}
                onChange={(event) => setValue(event.target.value)}
                required={required}
            />
            {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
            {warning && (
                <p className="flex items-start gap-1.5 text-xs text-amber-700 dark:text-amber-300">
                    <TriangleAlert className="mt-0.5 size-3.5 shrink-0" />
                    {warning}
                </p>
            )}
            <InputError message={error} />
        </div>
    );
}
