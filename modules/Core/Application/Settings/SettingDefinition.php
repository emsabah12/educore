<?php

namespace Modules\Core\Application\Settings;

/**
 * Definisi satu aturan berjenjang. ISI aturan (key, default, validasi) milik modul
 * HR/Academic; Core hanya menyediakan mekanismenya (PRD-000 §8.2).
 *
 * Contoh (di boot() HRServiceProvider):
 *
 *   $settings->define(new SettingDefinition(
 *       key: 'hr.attendance.check_in_time',
 *       label: 'Jam masuk',
 *       default: '07:00',
 *       rules: ['required', 'date_format:H:i'],
 *   ));
 */
final readonly class SettingDefinition
{
    /**
     * @param  array<int, mixed>  $rules  aturan validasi Laravel untuk nilainya
     * @param  string|null  $permission  permission yang boleh mengubah aturan ini; null = `core.settings.manage`.
     *                                   Mis. tarif gaji HR memakai `hr.payroll.manage` (PRD-001 §16.2).
     */
    public function __construct(
        public string $key,
        public string $label,
        public mixed $default,
        public array $rules,
        public ?string $permission = null,
    ) {}
}
