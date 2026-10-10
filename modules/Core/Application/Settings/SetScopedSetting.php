<?php

namespace Modules\Core\Application\Settings;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
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
 *   3. pengguna punya `core.settings.manage` yang cakupannya memuat target (§8.3) — 403/404;
 *   4. target belum dikunci oleh tingkat di atasnya.
 *
 * Pencatatan ke audit log (§8.2) disambungkan di F4 saat tabel audit_logs tersedia.
 */
final class SetScopedSetting
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuthorizationService $authorization,
        private readonly SettingRegistry $registry,
        private readonly ScopedSettingResolver $resolver,
        private readonly SettingPrecedence $precedence,
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

        return DB::transaction(function () use ($context, $key, $organizationId, $jenjang, $value, $enforced): ScopedSetting {
            // Antrekan penulisan aturan per yayasan (jarang terjadi). Mencegah dua penyimpanan
            // bersamaan menghasilkan baris ganda, dan pohon tidak berubah di tengah pemeriksaan.
            Tenant::query()->whereKey($context->tenantId)->lockForUpdate()->first();

            // Dicek setelah kunci: pohon tidak bisa berubah di antara pemeriksaan dan penyimpanan.
            $this->authorization
                ->inspectTarget(CoreAccess::SETTINGS_MANAGE, $organizationId, $jenjang)
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

        $setting->value = $value;
        $setting->is_enforced = $enforced;
        $setting->save();

        return $setting;
    }
}
