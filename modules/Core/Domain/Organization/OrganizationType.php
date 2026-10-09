<?php

namespace Modules\Core\Domain\Organization;

/**
 * Jenis node di pohon lembaga (PRD-000 §4.5).
 */
enum OrganizationType: string
{
    /** Lembaga pendidikan: SMP, MTs, MA, MDA, Pondok Pesantren, dst. */
    case Lembaga = 'LEMBAGA';

    /** Unit/kampus di dalam lembaga, mis. Unit 1 Pondok Pesantren. */
    case Unit = 'UNIT';

    /** Struktur pusat yayasan, mis. Biro Pendidikan, Biro SDM. */
    case Biro = 'BIRO';

    /**
     * Hanya LEMBAGA yang memakai category & jenjang.
     */
    public function requiresClassification(): bool
    {
        return $this === self::Lembaga;
    }
}
