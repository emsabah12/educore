<?php

declare(strict_types=1);

namespace Modules\Core\Entities;

use Symfony\Component\Uid\Uuid;

abstract class BaseEntity
{
    protected string $name;

    public function __construct(?string $name = null)
    {
        $this->name = $name ?? '';
    }

    public function getName(): string
    {
        return $this->name;
    }
}