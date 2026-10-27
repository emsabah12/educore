<?php

namespace Modules\Core\Application\Settings;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\Core\Application\Audit\AuditAction;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Application\Authorization\CoreAccess;
use Modules\Core\Domain\Organization\Jenjang;
use Modules\Core\Domain\Settings\Exceptions\SettingException;
use Modules\Core\Domain\Settings\ScopedSetting;
use Modules\Core\Domain\Tenancy\Tenant;
use Modules\Core\Domain\Tenancy\TenantContext;

/**
 * Menetapkan nilai aturan berjenjang di yayasan aktif (PRD-000 §8).
 *
 * Urutan pemeriksaan:
 *   1. aturan dikenal (didefinisikan modul);
 *   2. nilai lolos validasi definisi;
 *   3. pengguna punya `core.settings.manage` (atau permission khusus di definisi aturan) yang
 *      cakupannya memuat target (§8.3) — 403/404;
 *   4. target belum dikunci oleh tingkat di atasnya.
 *
 * Setiap penyimpanan dicatat di audit log beserta nilai lama & baru (§8.2, F4).
 */
final class SetScopedSetting
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuthorizationService $authorization,
        private readonly SettingRegistry $registry,
        private readonly ScopedSettingResolver $resolver,
        private readonly SettingPrecedence $precedence,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  string|null  $organizationId  null = tingkat Yayasan
     * @param  Jenjang|null  $jenjang  terisi = khusus lembaga berjenjang ini
     *
     * @throws AuthorizationException
     * @throws SettingException
     */
    public function handle(string $key, ?string $organizationId, ?Jenjang $jenjang, mixed $value, bool $enforced = false): ScopedSetting
    {
        $context = $this->tenantContext->require();
        $definition = $this->registry->get($key);

        if ($value === null) {
            throw SettingException::invalidValue('nilai wajib diisi.');
        }

        $validator = Validator::make(
            ['value' => $value],
            ['value' => $definition->rules],
            [],
            ['value' => $definition->label],
        );

        if ($validator->fails()) {
            throw SettingException::invalidValue($validator->errors()->first('value'));
        }

        $permission = $definition->permission ?? CoreAccess::SETTINGS_MANAGE;

        return DB::transaction(function () use ($context, $key, $organizationId, $jenjang, $value, $enforced, $permission): ScopedSetting {
            // Antrekan penulisan aturan per yayasan (jarang terjadi). Mencegah dua penyimpanan
            // bersamaan menghasilkan baris ganda, dan pohon tidak berubah di tengah pemeriksaan.
            Tenant::query()->whereKey($context->tenantId)->lockForUpdate()->first();

            // Dicek setelah kunci: pohon tidak bisa berubah di antara pemeriksaan dan penyimpanan.
            $this->authorization
                ->inspectTarget($permission, $organizationId, $jenjang)
                ->authorize();

            $path = $this->resolver->pathOf($context->tenantId, $organizationId);
            $rows = $this->resolver->rows($context->tenantId, $key, $path);

            $locked = $this->precedence->isLockedFromAbove(
                $path,
                $organizationId,
                $jenjang?->value,
                array_column($rows, 'meta'),
            );

            if ($locked) {
                throw SettingException::lockedFromAbove();
            }

            $target = [
                'tenant_id' => $context->tenantId,
                'organization_id' => $organizationId,
                'jenjang' => $jenjang?->value,
                'key' => $key,
            ];

            return $this->save($target, $value, $enforced);
        });
    }

    /**
     * @param  array{tenant_id: string, organization_id: string|null, jenjang: string|null, key: string}  $target
     */
    private function save(array $target, mixed $value, bool $enforced): ScopedSetting
    {
        $setting = ScopedSetting::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $target['tenant_id'])
            ->where('organization_id', $target['organization_id'])
            ->where('jenjang', $target['jenjang'])
            ->where('key', $target['key'])
            ->first() ?? new ScopedSetting($target);

        $isNew = ! $setting->exists;
        $oldValue = $isNew ? null : $setting->value;
        $oldEnforced = $isNew ? null : $setting->is_enforced;

        $setting->value = $value;
        $setting->is_enforced = $enforced;
        $setting->save();

        // Setiap perubahan aturan dicatat beserta nilai lama & baru (PRD-000 §8.2).
        $this->audit->record(
            AuditAction::SETTING_CHANGED,
            AuditLogger::SUBJECT_SETTING,
            $setting->id,
            [
                'key' => $target['key'],
                'jenjang' => $target['jenjang'],
                'value' => [$oldValue, $value],
                'is_enforced' => [$oldEnforced, $enforced],
            ],
            organizationId: $target['organization_id'],
            tenantId: $target['tenant_id'],
        );

        return $setting;
    }
}
