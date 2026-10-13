<?php

namespace Modules\HR\Domain\Employee;

/**
 * Status pegawai (PRD-001 §4.3): AKTIF selama ada periode kepegawaian yang terbuka.
 */
enum EmployeeStatus: string
{
    case Active = 'AKTIF';
    case Ended = 'BERHENTI';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Aktif',
            self::Ended => 'Berhenti',
        };
    }
}
