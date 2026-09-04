<?php

declare(strict_types=1);

namespace Modules\HR\Http\Requests;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreWorkspaceEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Catatan (HR-017 §3.4 keputusan #2, LOCKED): `organization_id` /
     * `organization_unit_id` SENGAJA TIDAK menerima input dari payload
     * sama sekali — keduanya selalu diambil dari OrganizationalContext
     * aktif (header X-EduCore-Organizational-Assignment-Id), bukan dari
     * request body. Kalau actor bisa memilih sendiri organisasi/unit di
     * payload, itu jadi celah privilege escalation (bisa "titip" Employee
     * ke organisasi lain di luar workspace-nya).
     *
     * `employment_type_id` WAJIB diisi (bukan opsional) sejak revisi
     * HR-017 §3.4 keputusan #1 — Employment akan langsung diaktifkan
     * dalam transaksi yang sama, dan aktivasi mensyaratkan
     * employment_type_id terisi.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $tenantId = $this->attributes->get(
            'authenticated_tenant_id',
        );
        $tenantId = is_string($tenantId) ? $tenantId : '';

        return [
            'nama' => [
                'required',
                'string',
                'max:255',
            ],
            'nip' => [
                'required',
                'string',
                'max:50',
            ],
            'jabatan' => [
                'required',
                'string',
                'max:100',
            ],
            'employment_type_id' => [
                'required',
                'uuid',
                Rule::exists('employment_types', 'id')
                    ->where(
                        static fn(Builder $query): Builder => $query->where(
                            'tenant_id',
                            $tenantId,
                        ),
                    ),
            ],
        ];
    }
}
