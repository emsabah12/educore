<?php

namespace Modules\HR\Application\Catalog;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Domain\Catalog\CatalogItem;
use Modules\HR\Domain\Catalog\CatalogKind;
use Modules\HR\Domain\Catalog\Exceptions\CatalogException;

/**
 * Menambah item katalog SDM (PRD-001 §4.3). Kode diubah ke huruf besar, unik per yayasan
 * per jenis katalog, dan tidak bisa diubah setelah dibuat.
 */
final class CreateCatalogItem
{
    public const CODE_PATTERN = '/^[A-Z0-9][A-Z0-9-]{1,29}$/';

    public const NAME_MAX_LENGTH = 100;

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws CatalogException
     */
    public function handle(string $tenantId, CatalogKind $kind, string $code, string $name): CatalogItem
    {
        $code = Str::upper(trim($code));
        $name = trim($name);

        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            throw CatalogException::invalidCode();
        }

        if ($name === '' || mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw CatalogException::invalidName();
        }

        try {
            return DB::transaction(function () use ($tenantId, $kind, $code, $name): CatalogItem {
                $exists = $kind->query()
                    ->withoutGlobalScope('tenant')
                    ->where('tenant_id', $tenantId)
                    ->where('code', $code)
                    ->exists();

                if ($exists) {
                    throw CatalogException::duplicateCode($kind, $code);
                }

                $item = $kind->newItem();
                $item->tenant_id = $tenantId;
                $item->code = $code;
                $item->name = $name;
                $item->save();

                $this->audit->record(
                    HrAuditAction::CATALOG_CREATED,
                    $kind->auditSubject(),
                    $item->id,
                    ['katalog' => $kind->label(), 'code' => $code, 'name' => $name],
                    tenantId: $tenantId,
                );

                return $item;
            });
        } catch (UniqueConstraintViolationException) {
            // Jaga-jaga bila dua request menambah kode yang sama pada saat bersamaan.
            throw CatalogException::duplicateCode($kind, $code);
        }
    }
}
