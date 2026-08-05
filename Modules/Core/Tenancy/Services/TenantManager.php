<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Services;

use InvalidArgumentException;
use Modules\Core\Tenancy\Contracts\TenantRepositoryInterface;

final class TenantManager
{
    public function __construct(
        private readonly TenantRepositoryInterface $tenantRepository,
    ) {}

    /**
     * Membuat tenant melalui canonical persistence repository.
     *
     * Service ini digunakan oleh jalur non-HTTP seperti Artisan command,
     * sehingga tetap melakukan validasi input internal.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function createTenant(array $data): array
    {
        $payload = $this->normalizeAndValidate(
            $data,
        );

        return $this->tenantRepository->create(
            $payload,
        );
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{
     *     name: string,
     *     subdomain: string,
     *     domain: string|null,
     *     is_active: bool,
     *     settings: array<string, mixed>
     * }
     */
    private function normalizeAndValidate(
        array $data,
    ): array {
        $name = $this->normalizeRequiredString(
            $data['name'] ?? null,
            'Tenant name',
        );

        $subdomain = strtolower(
            $this->normalizeRequiredString(
                $data['subdomain'] ?? null,
                'Tenant subdomain',
            ),
        );

        if (
            mb_strlen($name) < 3
            || mb_strlen($name) > 255
        ) {
            throw new InvalidArgumentException(
                'Tenant name must contain between 3 and 255 characters.',
            );
        }

        if (
            preg_match(
                '/^(?:[a-z0-9]|[a-z0-9][a-z0-9-]{0,48}[a-z0-9])$/',
                $subdomain,
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'Tenant subdomain must contain only lowercase letters, numbers, and hyphens.',
            );
        }

        $domain = $this->normalizeOptionalString(
            $data['domain'] ?? null,
        );

        if ($domain !== null) {
            $domain = strtolower($domain);

            if (
                mb_strlen($domain) > 255
                || filter_var(
                    $domain,
                    FILTER_VALIDATE_DOMAIN,
                    FILTER_FLAG_HOSTNAME,
                ) === false
            ) {
                throw new InvalidArgumentException(
                    'Tenant custom domain is invalid.',
                );
            }
        }

        $settings = $data['settings'] ?? [];

        if (! is_array($settings)) {
            throw new InvalidArgumentException(
                'Tenant settings must be an array.',
            );
        }

        $isActive = $data['is_active'] ?? true;

        if (! is_bool($isActive)) {
            throw new InvalidArgumentException(
                'Tenant active status must be boolean.',
            );
        }

        return [
            'name' => $name,
            'subdomain' => $subdomain,
            'domain' => $domain,
            'is_active' => $isActive,
            'settings' => $settings,
        ];
    }

    private function normalizeRequiredString(
        mixed $value,
        string $field,
    ): string {
        if (! is_string($value)) {
            throw new InvalidArgumentException(
                sprintf('%s is required.', $field),
            );
        }

        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException(
                sprintf('%s is required.', $field),
            );
        }

        return $value;
    }

    private function normalizeOptionalString(
        mixed $value,
    ): ?string {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException(
                'Tenant custom domain must be a string.',
            );
        }

        $value = trim($value);

        return $value !== ''
            ? $value
            : null;
    }
}
