<?php

namespace Modules\HR\Application\Catalog;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\HR\Application\Audit\HrAuditAction;
use Modules\HR\Domain\Catalog\CatalogItem;
use Modules\HR\Domain\Catalog\CatalogKind;
use Modules\HR\Domain\Catalog\Exceptions\CatalogException;

/**
 * Mengubah nama, menonaktifkan, dan mengaktifkan kembali item katalog SDM (PRD-001 §4.3).
 *
 * Item tidak pernah dihapus: item nonaktif tidak bisa dipilih untuk data baru,
 * tetapi riwayat yang sudah merujuknya tetap tampil apa adanya.
 */
final class UpdateCatalogItem
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws CatalogException
     */
    public function rename(string $tenantId, CatalogKind $kind, string $itemId, string $name): CatalogItem
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > CreateCatalogItem::NAME_MAX_LENGTH) {
            throw CatalogException::invalidName();
        }

        return DB::transaction(function () use ($tenantId, $kind, $itemId, $name): CatalogItem {
            $item = $this->lockItem($tenantId, $kind, $itemId);
            $oldName = $item->name;

            if ($oldName === $name) {
                return $item;
            }

            $item->name = $name;
            $item->save();

            $this->audit->record(
                HrAuditAction::CATALOG_RENAMED,
                $kind->auditSubject(),
                $item->id,
                ['katalog' => $kind->label(), 'code' => $item->code, 'name' => [$oldName, $name]],
                tenantId: $tenantId,
            );

            return $item;
        });
    }

    /**
     * @throws CatalogException
     */
    public function setActive(string $tenantId, CatalogKind $kind, string $itemId, bool $active): CatalogItem
    {
        return DB::transaction(function () use ($tenantId, $kind, $itemId, $active): CatalogItem {
            $item = $this->lockItem($tenantId, $kind, $itemId);

            if ($item->is_active === $active) {
                return $item;
            }

            $item->is_active = $active;
            $item->save();

            $this->audit->record(
                $active ? HrAuditAction::CATALOG_ACTIVATED : HrAuditAction::CATALOG_DEACTIVATED,
                $kind->auditSubject(),
                $item->id,
                ['katalog' => $kind->label(), 'code' => $item->code, 'is_active' => [! $active, $active]],
                tenantId: $tenantId,
            );

            return $item;
        });
    }

    private function lockItem(string $tenantId, CatalogKind $kind, string $itemId): CatalogItem
    {
        // ID asal-asalan dijawab "tidak ditemukan", bukan error database.
        if (! Str::isUuid($itemId)) {
            throw CatalogException::notFound();
        }

        $item = $kind->query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereKey($itemId)
            ->lockForUpdate()
            ->first();

        if (! $item instanceof CatalogItem) {
            throw CatalogException::notFound();
        }

        return $item;
    }
}
