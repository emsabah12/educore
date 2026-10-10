<?php

namespace Modules\Core\Contracts;

/**
 * Data yang dimiliki sebuah node pohon lembaga (PRD-000 §5: setiap data bisnis
 * wajib menyimpan organization_id pemiliknya).
 *
 * Model yang memasang interface ini bisa langsung dicek dengan Gate, mis.:
 *
 *   Gate::authorize('hr.employees.view', $employee);
 *
 * Hasilnya: boleh, 403 (ada di cakupan tapi tanpa izin aksi), atau 404 (di luar cakupan).
 */
interface OwnedByOrganization
{
    public function owningTenantId(): string;

    public function owningOrganizationId(): string;
}
