<?php

namespace Modules\HR\Application\Import;

use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Person\Gender;
use Modules\HR\Domain\Catalog\CatalogItem;
use Modules\HR\Domain\Employee\EducationLevel;

/**
 * Baris impor yang sedang diperiksa (dipakai di dalam EmployeeImportValidator saja).
 */
final class ImportRowDraft
{
    /** @var list<array{column: string, label: string, message: string}> */
    public array $errors = [];

    /** @var list<string> */
    public array $warnings = [];

    public ?string $name = null;

    public ?Gender $gender = null;

    public ?string $birthPlace = null;

    public ?string $birthDate = null;

    public ?string $phone = null;

    public ?string $address = null;

    public ?string $employeeNumber = null;

    public ?string $nuptk = null;

    public ?EducationLevel $educationLevel = null;

    public ?string $educationMajor = null;

    public ?CatalogItem $employmentType = null;

    public ?CatalogItem $classification = null;

    public ?string $startDate = null;

    public ?Organization $organization = null;

    public ?CatalogItem $position = null;

    /** Isian mentah untuk ditampilkan bila kodenya tidak dikenal. */
    public ?string $organizationInput = null;

    public ?string $positionInput = null;

    public ?string $employmentTypeInput = null;

    public function __construct(
        public readonly int $line,
    ) {}

    public function error(string $column, string $message): void
    {
        $this->errors[] = [
            'column' => $column,
            'label' => EmployeeImportColumns::label($column),
            'message' => $message,
        ];
    }

    public function hasError(string $column): bool
    {
        foreach ($this->errors as $error) {
            if ($error['column'] === $column) {
                return true;
            }
        }

        return false;
    }
}
