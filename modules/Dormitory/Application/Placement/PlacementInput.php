<?php

namespace Modules\Dormitory\Application\Placement;

use Modules\Dormitory\Domain\Placement\PlacementCategory;

/**
 * Isian Tempatkan / Rencanakan (PRD-008 §16.4), sudah dirapikan pemanggil.
 */
final readonly class PlacementInput
{
    public function __construct(
        public string $membershipId,
        public PlacementCategory $category,
        /** true = Rencanakan; false = Tempatkan langsung (Aktif). */
        public bool $plan,
        /** Kosong = disarankan otomatis bila diwajibkan dasar kapasitas. */
        public ?string $bedId,
        public ?string $lockerId,
        /** Tempatkan langsung: tanggal masuk (wajib). Rencana: rencana tanggal masuk (opsional). Y-m-d. */
        public ?string $date,
        public ?string $note,
    ) {}
}
