<?php

namespace Modules\Core\Application\Organization;

use Illuminate\Support\Facades\DB;
use Modules\Core\Application\Audit\AuditAction;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Domain\Organization\Exceptions\OrganizationDetailsException;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Organization\OrganizationOwnership;
use Modules\Core\Domain\Organization\OrganizationType;

/**
 * Mengubah data resmi lembaga (PRD-000 §4.6; PRD-001 §21.1, OD-HR-26): NPSN (8 angka, unik di yayasan),
 * status Negeri/Swasta, kecamatan, kab/kota, provinsi. Semua opsional (kosong = belum diisi). Hanya untuk
 * node bertipe LEMBAGA. Audit hanya mencatat nama isian yang berubah, tanpa isinya.
 */
final class UpdateOrganizationDetails
{
    use LocksTenantTree;

    public const REGION_MAX_LENGTH = 100;

    /** Nama isian di audit. */
    private const AUDIT_LABELS = [
        'npsn' => 'NPSN',
        'ownership' => 'status',
        'district' => 'kecamatan',
        'regency' => 'kab/kota',
        'province' => 'provinsi',
    ];

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{npsn?: string|null, ownership?: string|null, district?: string|null, regency?: string|null, province?: string|null}  $input
     *
     * @throws OrganizationDetailsException
     */
    public function handle(string $tenantId, string $organizationId, array $input): Organization
    {
        $values = self::normalize($input);

        return DB::transaction(function () use ($tenantId, $organizationId, $values): Organization {
            $this->lockTenant($tenantId);

            $organization = $this->findInTenant($tenantId, $organizationId);

            if ($organization === null) {
                throw OrganizationDetailsException::make('form', 'Lembaga/unit tidak ditemukan di yayasan ini.');
            }

            if ($organization->type !== OrganizationType::Lembaga) {
                throw OrganizationDetailsException::make('form', 'Data resmi hanya untuk node berjenis lembaga.');
            }

            if ($values['npsn'] !== null && Organization::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('npsn', $values['npsn'])
                ->whereKeyNot($organization->id)
                ->exists()) {
                throw OrganizationDetailsException::make('npsn', 'NPSN ini sudah dipakai lembaga lain di yayasan ini.');
            }

            $changed = [];

            foreach ($values as $field => $value) {
                $current = $field === 'ownership' ? $organization->ownership?->value : $organization->{$field};

                if ($current !== $value) {
                    $changed[] = self::AUDIT_LABELS[$field];
                }
            }

            if ($changed === []) {
                return $organization;
            }

            $organization->npsn = $values['npsn'];
            $organization->ownership = $values['ownership'] === null ? null : OrganizationOwnership::from($values['ownership']);
            $organization->district = $values['district'];
            $organization->regency = $values['regency'];
            $organization->province = $values['province'];
            $organization->save();

            $this->audit->record(
                AuditAction::ORGANIZATION_DETAILS_UPDATED,
                AuditLogger::SUBJECT_ORGANIZATION,
                $organization->id,
                ['isian' => $changed],
                organizationId: $organization->id,
                tenantId: $tenantId,
            );

            return $organization;
        });
    }

    /**
     * @param  array<string, string|null>  $input
     * @return array{npsn: string|null, ownership: string|null, district: string|null, regency: string|null, province: string|null}
     *
     * @throws OrganizationDetailsException
     */
    private static function normalize(array $input): array
    {
        $text = function (string $field) use ($input): ?string {
            $value = trim((string) ($input[$field] ?? ''));

            return $value === '' ? null : preg_replace('/\s+/u', ' ', $value);
        };

        $npsn = $text('npsn');

        if ($npsn !== null && preg_match('/^\d{8}$/', $npsn) !== 1) {
            throw OrganizationDetailsException::make('npsn', 'NPSN harus 8 angka.');
        }

        $ownership = $text('ownership');

        if ($ownership !== null && OrganizationOwnership::tryFrom($ownership) === null) {
            throw OrganizationDetailsException::make('ownership', 'Pilih status Negeri atau Swasta.');
        }

        $values = ['npsn' => $npsn, 'ownership' => $ownership];

        foreach (['district' => 'Kecamatan', 'regency' => 'Kab/kota', 'province' => 'Provinsi'] as $field => $label) {
            $value = $text($field);

            if ($value !== null && mb_strlen($value) > self::REGION_MAX_LENGTH) {
                throw OrganizationDetailsException::make($field, $label.' paling panjang '.self::REGION_MAX_LENGTH.' karakter.');
            }

            $values[$field] = $value;
        }

        return $values;
    }
}
