<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Contracts;

interface AuthorizationContextResolverInterface
{
    /**
     * Resolve authorization context dari application runtime.
     *
     * Resolver wajib fail-closed apabila:
     *
     * - user belum authenticated
     * - tenant context belum resolved
     * - membership tidak ditemukan
     * - membership tidak valid/aktif
     */
    public function resolve(): AuthorizationContextInterface;
}
