<?php

declare(strict_types=1);

namespace Modules\Core\Person\Entities;

use DateTimeImmutable;
use InvalidArgumentException;
use Modules\Core\Person\Enums\PersonLegalSex;
use Modules\Core\Person\Enums\PersonStatus;
use Modules\Core\Person\Exceptions\InvalidPersonLifecycleTransitionException;
use Modules\Core\Person\ValueObjects\PersonName;
use Modules\Core\Support\Uuid\UuidV7;

final class Person
{
    private PersonStatus $status;

    private ?string $givenName;

    private ?string $middleName;

    private ?string $familyName;

    private ?string $birthPlaceName;

    private ?string $birthCountryCode;

    private ?string $civilStatus;

    public function __construct(
        private readonly string $id,
        private PersonName $name,
        PersonStatus $status = PersonStatus::ACTIVE,
        ?string $givenName = null,
        ?string $middleName = null,
        ?string $familyName = null,
        private readonly ?DateTimeImmutable $birthDate = null,
        ?string $birthPlaceName = null,
        ?string $birthCountryCode = null,
        private readonly ?PersonLegalSex $legalSex = null,
        ?string $civilStatus = null,
    ) {
        if (! UuidV7::validate($id)) {
            throw new InvalidArgumentException(
                'Person identifier must be a valid UUIDv7.',
            );
        }

        $this->givenName = self::normalizeOptionalString(
            $givenName,
            255,
            'Person given name',
        );
        $this->middleName = self::normalizeOptionalString(
            $middleName,
            255,
            'Person middle name',
        );
        $this->familyName = self::normalizeOptionalString(
            $familyName,
            255,
            'Person family name',
        );
        $this->birthPlaceName = self::normalizeOptionalString(
            $birthPlaceName,
            255,
            'Person birth place name',
        );
        $this->birthCountryCode = self::normalizeCountryCode(
            $birthCountryCode,
        );
        $this->civilStatus = self::normalizeOptionalString(
            $civilStatus,
            32,
            'Person civil status',
        );
        $this->status = $status;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): PersonName
    {
        return $this->name;
    }

    public function rename(PersonName $name): void
    {
        $this->name = $name;
    }

    public function givenName(): ?string
    {
        return $this->givenName;
    }

    public function middleName(): ?string
    {
        return $this->middleName;
    }

    public function familyName(): ?string
    {
        return $this->familyName;
    }

    public function birthDate(): ?DateTimeImmutable
    {
        return $this->birthDate;
    }

    public function birthPlaceName(): ?string
    {
        return $this->birthPlaceName;
    }

    public function birthCountryCode(): ?string
    {
        return $this->birthCountryCode;
    }

    public function legalSex(): ?PersonLegalSex
    {
        return $this->legalSex;
    }

    public function civilStatus(): ?string
    {
        return $this->civilStatus;
    }

    public function status(): PersonStatus
    {
        return $this->status;
    }

    public function activate(): void
    {
        $this->transitionTo(PersonStatus::ACTIVE);
    }

    public function deactivate(): void
    {
        $this->transitionTo(PersonStatus::INACTIVE);
    }

    public function archive(): void
    {
        $this->transitionTo(PersonStatus::ARCHIVED);
    }

    public function markDeceased(): void
    {
        $this->transitionTo(PersonStatus::DECEASED);
    }

    public function isActive(): bool
    {
        return $this->status === PersonStatus::ACTIVE;
    }

    public function isInactive(): bool
    {
        return $this->status === PersonStatus::INACTIVE;
    }

    public function isArchived(): bool
    {
        return $this->status === PersonStatus::ARCHIVED;
    }

    public function isDeceased(): bool
    {
        return $this->status === PersonStatus::DECEASED;
    }

    private function transitionTo(PersonStatus $target): void
    {
        if ($this->status === $target) {
            return;
        }

        if (! $this->canTransitionTo($target)) {
            throw InvalidPersonLifecycleTransitionException::from(
                current: $this->status,
                target: $target,
            );
        }

        $this->status = $target;
    }

    private function canTransitionTo(PersonStatus $target): bool
    {
        return match ($this->status) {
            PersonStatus::ACTIVE => in_array(
                $target,
                [
                    PersonStatus::INACTIVE,
                    PersonStatus::ARCHIVED,
                    PersonStatus::DECEASED,
                ],
                true,
            ),

            PersonStatus::INACTIVE => in_array(
                $target,
                [
                    PersonStatus::ACTIVE,
                    PersonStatus::ARCHIVED,
                    PersonStatus::DECEASED,
                ],
                true,
            ),

            PersonStatus::ARCHIVED,
            PersonStatus::DECEASED => false,
        };
    }

    private static function normalizeOptionalString(
        ?string $value,
        int $maxLength,
        string $label,
    ): ?string {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException(
                sprintf('%s cannot be an empty string.', $label),
            );
        }

        if (mb_strlen($value) > $maxLength) {
            throw new InvalidArgumentException(
                sprintf('%s cannot exceed %d characters.', $label, $maxLength),
            );
        }

        return $value;
    }

    private static function normalizeCountryCode(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = strtoupper(trim($value));

        if (preg_match('/^[A-Z]{2}$/', $value) !== 1) {
            throw new InvalidArgumentException(
                'Person birth country code must use a two-letter ISO 3166-1 alpha-2 format.',
            );
        }

        return $value;
    }
}
