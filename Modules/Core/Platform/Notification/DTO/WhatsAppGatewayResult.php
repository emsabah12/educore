<?php

declare(strict_types=1);

namespace Modules\Core\Platform\Notification\DTO;

final readonly class WhatsAppGatewayResult
{
    /**
     * @param array<string, mixed> $metadata
     */
    private function __construct(
        public bool $successful,
        public array $metadata,
        public ?string $failureCode,
    ) {}

    /**
     * @param array<string, mixed> $metadata
     */
    public static function success(
        array $metadata = [],
    ): self {
        return new self(
            successful: true,
            metadata: $metadata,
            failureCode: null,
        );
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function failure(
        string $failureCode,
        array $metadata = [],
    ): self {
        return new self(
            successful: false,
            metadata: $metadata,
            failureCode: self::normalizeFailureCode(
                $failureCode,
            ),
        );
    }

    /**
     * Failure code hanya boleh berupa identifier teknis.
     *
     * Ini mencegah token, URL, atau raw response provider disisipkan
     * ke log dan exception melalui failure code.
     */
    private static function normalizeFailureCode(
        string $failureCode,
    ): string {
        $failureCode = strtolower(
            trim($failureCode),
        );

        if (
            $failureCode === ''
            || preg_match(
                '/\A[a-z0-9._-]{1,64}\z/',
                $failureCode,
            ) !== 1
        ) {
            return 'unknown_failure';
        }

        return $failureCode;
    }
}
