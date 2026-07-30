<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Context;

use InvalidArgumentException;
use Modules\Core\Authorization\Contracts\AuthorizationContextInterface;

final readonly class AuthorizationContext implements AuthorizationContextInterface
{
    public function __construct(
        private string $userId,
        private string $tenantId,
        private string $membershipId,
    ) {
        $this->assertValidIdentifier(
            $this->userId,
            'userId',
        );

        $this->assertValidIdentifier(
            $this->tenantId,
            'tenantId',
        );

        $this->assertValidIdentifier(
            $this->membershipId,
            'membershipId',
        );
    }

    public function userId(): string
    {
        return $this->userId;
    }

    public function tenantId(): string
    {
        return $this->tenantId;
    }

    public function membershipId(): string
    {
        return $this->membershipId;
    }

    /**
     * Pastikan identifier tidak kosong atau hanya whitespace.
     *
     * Validasi format UUID / identifier spesifik tidak dilakukan di sini
     * karena format identity dapat berkembang dan bukan tanggung jawab
     * AuthorizationContext.
     */
    private function assertValidIdentifier(
        string $value,
        string $field,
    ): void {
        if (trim($value) === '') {
            throw new InvalidArgumentException(
                sprintf(
                    'Authorization context field [%s] cannot be empty.',
                    $field,
                ),
            );
        }
    }
}
