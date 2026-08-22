<?php

declare(strict_types=1);

return [
    /*
     * Explicit opt-in.
     *
     * Development smoke data must never be created simply because
     * the normal DatabaseSeeder was executed.
     */
    'enabled' => env(
        'DEVELOPMENT_SMOKE_SEED_ENABLED',
        false,
    ),

    /*
     * Defense-in-depth database locator.
     *
     * The seeder refuses to run unless this value exactly matches
     * the active connection's configured database.
     */
    'database' => env(
        'DEVELOPMENT_SMOKE_DATABASE',
    ),

    'tenant' => [
        'name' => env(
            'DEVELOPMENT_SMOKE_TENANT_NAME',
            'EduCore Development School',
        ),

        'subdomain' => env(
            'DEVELOPMENT_SMOKE_TENANT_SUBDOMAIN',
            'educore-development',
        ),
    ],

    'tenant_user' => [
        'name' => env(
            'DEVELOPMENT_SMOKE_TENANT_USER_NAME',
            'EduCore Development User',
        ),

        /*
         * No default credential is intentionally provided.
         *
         * Local operators must explicitly provide both values.
         */
        'email' => env(
            'DEVELOPMENT_SMOKE_TENANT_USER_EMAIL',
        ),

        'password' => env(
            'DEVELOPMENT_SMOKE_TENANT_USER_PASSWORD',
        ),
    ],
];
