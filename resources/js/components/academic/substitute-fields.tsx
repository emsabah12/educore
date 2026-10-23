import type { SubstituteCandidate } from '@/components/academic/teaching';
import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';

type Option = { value: string; label: string };

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';

/**
 * Pilihan guru pengganti (PRD-002 §20.3): guru piket & yang tersedia di atas; yang bentrok jadwal
 * atau sedang cuti tetap terlihat tetapi tidak bisa dipilih. Server tetap memeriksa ulang.
 */
export function SubstituteSelect({
    id,
    candidates,
    defaultValue,
    error,
}: {
    id: string;
    candidates: SubstituteCandidate[];
    defaultValue: string;
    error?: string;
}) {
    const available = candidates.filter((item) => item.available).length;

    return (
        <div className="grid content-start gap-1.5">
            <Label htmlFor={id}>Guru pengganti</Label>
            <select
                id={id}
                name="substitute_employee_id"
                className={selectClass}
                defaultValue={defaultValue}
                required
            >
                <option value="" disabled>
                    {available === 0
                        ? 'Tidak ada guru yang tersedia'
                        : 'Pilih guru pengganti'}
                </option>
                {candidates.map((candidate) => (
                    <option
                        key={candidate.value}
                        value={candidate.value}
                        disabled={
                            !candidate.available &&
                            candidate.value !== defaultValue
                        }
                    >
                        {candidate.label}
                        {candidate.duty && ' (piket)'}
                        {candidate.note && ` — ${candidate.note}`}
                    </option>
                ))}
            </select>
            <InputError message={error} />
        </div>
    );
}

export function ReasonSelect({
    id,
    reasons,
    defaultValue,
    error,
}: {
    id: string;
    reasons: Option[];
    defaultValue: string;
    error?: string;
}) {
    return (
        <div className="grid content-start gap-1.5">
            <Label htmlFor={id}>Alasan guru tidak mengajar</Label>
            <select
                id={id}
                name="absence_reason"
                className={selectClass}
                defaultValue={defaultValue}
                required
            >
                <option value="" disabled>
                    Pilih alasan
                </option>
                {reasons.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
            <InputError message={error} />
        </div>
    );
}
