<?php

namespace Modules\Dormitory\Domain\Placement;

/**
 * Alasan penempatan selesai (PRD-008 §5.2, §16.1). Mutasi & Akhir tahun hanya diisi sistem (DM-4, DM-5).
 */
enum EndReason: string
{
    case LeftDormitory = 'KELUAR_ASRAMA';
    case LeftSchool = 'KELUAR_LEMBAGA';
    case Mutation = 'MUTASI';
    case YearEnd = 'AKHIR_TAHUN';
    case Other = 'LAINNYA';

    public function label(): string
    {
        return match ($this) {
            self::LeftDormitory => 'Keluar asrama',
            self::LeftSchool => 'Lulus/keluar lembaga',
            self::Mutation => 'Pindah kamar (mutasi)',
            self::YearEnd => 'Akhir tahun ajaran',
            self::Other => 'Lainnya',
        };
    }

    /**
     * Alasan yang boleh dipilih petugas saat check-out.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function checkOutOptions(): array
    {
        return array_map(
            fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            [self::LeftDormitory, self::LeftSchool, self::Other],
        );
    }

    public function chosenByStaff(): bool
    {
        return in_array($this, [self::LeftDormitory, self::LeftSchool, self::Other], true);
    }
}
