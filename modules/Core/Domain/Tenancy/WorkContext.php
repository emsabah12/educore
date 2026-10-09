<?php

namespace Modules\Core\Domain\Tenancy;

use Modules\Core\Domain\Organization\Jenjang;

/**
 * Konteks kerja yang sedang aktif untuk satu request: yayasan + lembaga kerja (PRD-000 §6).
 *
 * Dibentuk ulang dan divalidasi setiap request oleh middleware EnsureWorkContext,
 * jadi tidak pernah dipercaya begitu saja dari session.
 */
final readonly class WorkContext
{
    public const WORKSPACE_TENANT = 'tenant';

    public const WORKSPACE_ORGANIZATION = 'organization';

    public const WORKSPACE_FUNCTIONAL = 'functional';

    public function __construct(
        public string $tenantId,
        public string $tenantName,
        public string $membershipId,
        public ?string $assignmentId,
        public ?string $organizationId,
        public ?string $organizationName,
        public ?Jenjang $jenjangFilter,
        public bool $canSwitchTenant,
        public bool $canSwitchWorkspace,
    ) {}

    /**
     * Jenis lembaga kerja: seluruh yayasan, satu node, atau fungsional (filter jenjang).
     */
    public function workspaceType(): string
    {
        if ($this->assignmentId === null) {
            return self::WORKSPACE_TENANT;
        }

        return $this->jenjangFilter === null ? self::WORKSPACE_ORGANIZATION : self::WORKSPACE_FUNCTIONAL;
    }

    /**
     * Label lembaga kerja untuk ditampilkan, mis. "MA Unit 1" atau "Semua MDA (Seluruh Yayasan)".
     */
    public function workspaceLabel(): string
    {
        return self::labelFor($this->organizationName, $this->jenjangFilter);
    }

    /**
     * Aturan label yang sama dipakai di halaman pilih lembaga kerja.
     */
    public static function labelFor(?string $organizationName, ?Jenjang $jenjangFilter): string
    {
        if ($jenjangFilter === null) {
            return $organizationName ?? 'Seluruh Yayasan';
        }

        $scope = $organizationName ?? 'Seluruh Yayasan';

        return "Semua {$jenjangFilter->label()} ({$scope})";
    }

    /**
     * Data yang dikirim ke browser lewat props Inertia `context`.
     *
     * @return array{tenant: array{id: string, name: string}, workspace: array{type: string, assignment_id: string|null, organization_id: string|null, label: string}, can_switch_tenant: bool, can_switch_workspace: bool}
     */
    public function toArray(): array
    {
        return [
            'tenant' => [
                'id' => $this->tenantId,
                'name' => $this->tenantName,
            ],
            'workspace' => [
                'type' => $this->workspaceType(),
                'assignment_id' => $this->assignmentId,
                'organization_id' => $this->organizationId,
                'label' => $this->workspaceLabel(),
            ],
            'can_switch_tenant' => $this->canSwitchTenant,
            'can_switch_workspace' => $this->canSwitchWorkspace,
        ];
    }
}
