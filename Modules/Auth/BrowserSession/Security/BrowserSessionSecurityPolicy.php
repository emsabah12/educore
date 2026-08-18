<?php

declare(strict_types=1);

namespace Modules\Auth\BrowserSession\Security;

use LogicException;

final class BrowserSessionSecurityPolicy
{
    /**
     * Session drivers that keep BrowserSession state on shared server-side
     * infrastructure and can support horizontally scaled production nodes.
     *
     * @var list<string>
     */
    private const PRODUCTION_SESSION_DRIVERS = [
        'database',
        'redis',
        'memcached',
        'dynamodb',
    ];

    /**
     * Fail closed when production BrowserSession configuration would weaken
     * the locked frontend security baseline.
     *
     * @param  array<string, mixed>  $sessionConfig
     */
    public function assertProductionReady(array $sessionConfig): void
    {
        $violations = [];

        if (! in_array(
            $sessionConfig['driver'] ?? null,
            self::PRODUCTION_SESSION_DRIVERS,
            true,
        )) {
            $violations[] = 'session driver must use shared server-side storage';
        }

        if (($sessionConfig['encrypt'] ?? false) !== true) {
            $violations[] = 'session payload encryption must be enabled';
        }

        $cookieName = $sessionConfig['cookie'] ?? null;

        if (
            ! is_string($cookieName)
            || ! str_starts_with($cookieName, '__Host-')
        ) {
            $violations[] = 'session cookie must use the __Host- prefix';
        }

        if (($sessionConfig['secure'] ?? false) !== true) {
            $violations[] = 'session cookie must be Secure';
        }

        if (($sessionConfig['http_only'] ?? false) !== true) {
            $violations[] = 'session cookie must be HttpOnly';
        }

        if (($sessionConfig['path'] ?? null) !== '/') {
            $violations[] = 'session cookie path must be /';
        }

        $domain = $sessionConfig['domain'] ?? null;

        if ($domain !== null && $domain !== '') {
            $violations[] = 'session cookie must be host-only';
        }

        if (
            ! is_string($sessionConfig['same_site'] ?? null)
            || strtolower($sessionConfig['same_site']) !== 'strict'
        ) {
            $violations[] = 'session cookie SameSite must be Strict';
        }

        if (($sessionConfig['partitioned'] ?? false) !== false) {
            $violations[] = 'session cookie must not be partitioned';
        }

        if ($violations === []) {
            return;
        }

        throw new LogicException(
            'Invalid production BrowserSession configuration: '
            .implode('; ', $violations)
            .'.',
        );
    }
}
