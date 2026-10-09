<?php

namespace Modules\Core;

use Modules\Core\Support\ModuleServiceProvider;

/**
 * Modul Core: Tenancy, pohon lembaga, Person, User, Membership,
 * Authorization, scoped settings, dan Audit (PRD-000).
 *
 * Aturan dependency: Core tidak boleh bergantung pada modul bisnis apa pun.
 */
class CoreServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return __DIR__;
    }
}
