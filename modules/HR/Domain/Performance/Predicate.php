<?php

namespace Modules\HR\Domain\Performance;

/**
 * Predikat nilai akhir ala PKG (OD-HR-18): Amat baik 91–100, Baik 76–90, Cukup 61–75, Sedang 51–60, Kurang ≤ 50.
 * Nilai akhir berdesimal satu dibandingkan dengan batas bawah tiap rentang tanpa pembulatan [ASUMSI HR-8b]:
 * 90,5 → Baik, 91,0 → Amat baik.
 */
enum Predicate: string
{
    case Excellent = 'AMAT_BAIK';
    case Good = 'BAIK';
    case Fair = 'CUKUP';
    case Moderate = 'SEDANG';
    case Poor = 'KURANG';

    public static function fromScore(float $score): self
    {
        return match (true) {
            $score >= 91 => self::Excellent,
            $score >= 76 => self::Good,
            $score >= 61 => self::Fair,
            $score >= 51 => self::Moderate,
            default => self::Poor,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Excellent => 'Amat baik',
            self::Good => 'Baik',
            self::Fair => 'Cukup',
            self::Moderate => 'Sedang',
            self::Poor => 'Kurang',
        };
    }
}
