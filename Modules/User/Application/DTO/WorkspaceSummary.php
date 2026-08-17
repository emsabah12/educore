<?php

declare(strict_types=1);

namespace Modules\User\Application\DTO;

final readonly class WorkspaceSummary
{
    public const TYPE_TENANT = 'TENANT';

    public const TYPE_ORGANIZATION = 'ORGANIZATION';

    public const TYPE_ORGANIZATION_UNIT = 'ORGANIZATION_UNIT';

    public function __construct(
        public string $type,
        public ?string $organizationalAssignmentId,
        public ?string $organizationId,
        public ?string $organizationUnitId,
        public string $label,
    ) {}

    /**
     * @return array{
     *     type: string,
     *     organizational_assignment_id: string|null,
     *     organization_id: string|null,
     *     organization_unit_id: string|null,
     *     label: string
     * }
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'organizational_assignment_id' =>
            $this->organizationalAssignmentId,
            'organization_id' =>
            $this->organizationId,
            'organization_unit_id' =>
            $this->organizationUnitId,
            'label' => $this->label,
        ];
    }
}
