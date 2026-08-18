<?php

declare(strict_types=1);

namespace Modules\Auth\Application;

enum AuthenticationChannel: string
{
    case MOBILE_API = 'mobile_api';
    case BROWSER_SESSION = 'browser_session';

    public function failedLoginDescription(string $email): string
    {
        return match ($this) {
            self::MOBILE_API => sprintf(
                'Gagal login via token untuk email: %s',
                $email,
            ),
            self::BROWSER_SESSION => sprintf(
                'Gagal login via browser session untuk email: %s',
                $email,
            ),
        };
    }
}
