<?php

namespace Modules\HR\Application\Leave;

use Illuminate\Validation\Rule;
use Modules\Core\Application\Settings\ScopedSettingResolver;
use Modules\Core\Application\Settings\SettingDefinition;

/**
 * Aturan berjenjang `hr.leave_approval` (PRD-001 §13.4, OD-HR-06): jumlah tahap persetujuan cuti per
 * jenjang/lembaga. 1 = Kepala Lembaga saja; 2 = Kepala Lembaga lalu Admin SDM. Bawaan 2.
 */
final class LeaveApprovalSetting
{
    public const KEY = 'hr.leave_approval';

    public const DEFAULT = 2;

    public function __construct(
        private readonly ScopedSettingResolver $settings,
    ) {}

    public static function definition(): SettingDefinition
    {
        return new SettingDefinition(
            key: self::KEY,
            label: 'Alur persetujuan cuti',
            default: self::DEFAULT,
            rules: ['required', 'integer', Rule::in([1, 2])],
        );
    }

    public static function label(int $steps): string
    {
        return $steps === 1 ? '1 tahap (Kepala Lembaga)' : '2 tahap (Kepala Lembaga, lalu Admin SDM)';
    }

    public function stepsFor(string $tenantId, string $organizationId): int
    {
        return $this->settings->resolve($tenantId, self::KEY, $organizationId)->value === 1 ? 1 : 2;
    }
}
