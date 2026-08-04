<?php

declare(strict_types=1);

namespace Modules\Auth\Application\Services;

use Modules\Auth\Application\DTO\ResolvedAuthenticatedIdentity;
use Modules\Auth\Token\Contracts\TokenManagerInterface;
use Modules\Core\Identity\Models\User;
use Illuminate\Support\Str;
use Throwable;

final readonly class AuthenticatedIdentityResolver
{
    public function __construct(
        private TokenManagerInterface $tokenManager,
    ) {}

    /**
     * Resolve canonical active user dari bearer token.
     *
     * Resolver ini tidak membentuk tenant context dan tidak melakukan
     * authorization role atau permission.
     */
    public function resolve(
        string $bearerToken,
    ): ?ResolvedAuthenticatedIdentity {
        $bearerToken = trim($bearerToken);

        if ($bearerToken === '') {
            return null;
        }

        try {
            $claims = $this->tokenManager->validateAndExtract(
                $bearerToken,
            );
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        if (! is_array($claims)) {
            return null;
        }

        $userId = $this->extractStringClaim(
            $claims,
            'user_id',
        );

        if (! Str::isUuid($userId)) {
            return null;
        }

        if ($userId === null) {
            return null;
        }

        $user = User::query()->find($userId);

        if ($user === null || ! $this->isActiveUser($user)) {
            return null;
        }

        return new ResolvedAuthenticatedIdentity(
            user: $user,
            userId: $userId,
            claims: $claims,
        );
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function extractStringClaim(
        array $claims,
        string $claim,
    ): ?string {
        $value = $claims[$claim] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== ''
            ? $value
            : null;
    }

    private function isActiveUser(
        User $user,
    ): bool {
        $status = $user->getAttribute('status');

        return is_string($status)
            && strtoupper(trim($status)) === 'ACTIVE';
    }
}
