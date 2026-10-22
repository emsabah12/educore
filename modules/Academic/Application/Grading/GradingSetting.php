<?php

namespace Modules\Academic\Application\Grading;

use Closure;
use Modules\Core\Application\Settings\ScopedSettingResolver;
use Modules\Core\Application\Settings\SettingDefinition;

/**
 * Aturan berjenjang `academic.grading` (PRD-002 §19 AC-5a): KKM, komponen & bobot, batas predikat
 * per jenjang/lembaga. Bawaan GradingRules::defaults(). Mekanisme "terdekat menang, kecuali
 * dikunci" & audit nilai lama & baru milik Core (PRD-000 §8).
 */
final class GradingSetting
{
    public const KEY = 'academic.grading';

    public function __construct(
        private readonly ScopedSettingResolver $settings,
    ) {}

    public static function definition(): SettingDefinition
    {
        return new SettingDefinition(
            key: self::KEY,
            label: 'Aturan penilaian',
            default: GradingRules::defaults()->toArray(),
            rules: ['required', 'array', function (string $attribute, mixed $value, Closure $fail): void {
                $problem = GradingRules::problem($value);

                if ($problem !== null) {
                    $fail($problem);
                }
            }],
        );
    }

    /**
     * Aturan penilaian yang berlaku di lembaga ini.
     */
    public function rulesFor(string $tenantId, string $organizationId): GradingRules
    {
        return GradingRules::fromValue($this->settings->resolve($tenantId, self::KEY, $organizationId)->value);
    }
}
