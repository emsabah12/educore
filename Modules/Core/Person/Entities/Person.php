<?php

declare(strict_types=1);

namespace Modules\Core\Person\Entities;

use Modules\Core\Person\Enums\PersonStatus;
use Modules\Core\Person\Exceptions\InvalidPersonLifecycleTransitionException;
use Modules\Core\Person\ValueObjects\PersonName;
use RuntimeException;

final class Person
{
    private PersonStatus $status;

    public function __construct(
        private readonly string $id,
        private PersonName $name,
        PersonStatus $status = PersonStatus::ACTIVE,
    ) {
        $id = trim($id);

        if ($id === '') {
            throw new RuntimeException(
                'Person identifier cannot be empty.',
            );
        }

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

        if (!$this->canTransitionTo($target)) {
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
}
