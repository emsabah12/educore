<?php

declare(strict_types=1);

namespace Modules\Auth\Http\Guards;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable as UserContract;

/**
 * User Provider untuk Global Identity.
 *
 * Tanggung jawab class ini hanya mengambil User berdasarkan
 * credential identity seperti email.
 *
 * Tenant dan membership TIDAK menjadi bagian dari query identity.
 *
 * Resolusi tenant dilakukan secara terpisah melalui Membership
 * dan Tenant Context pada layer authorization/context.
 */
final class TenantAwareUserProvider extends EloquentUserProvider
{
    /**
     * Mengambil user berdasarkan credential identity.
     *
     * Catatan arsitektur:
     *
     * - users adalah global identity.
     * - users tidak memiliki tenant_id.
     * - membership menjadi penghubung antara user dan tenant.
     * - tenant context tidak boleh digunakan untuk memfilter
     *   tabel users secara langsung.
     *
     * @param array<string, mixed> $credentials
     */
    public function retrieveByCredentials(array $credentials): ?UserContract
    {
        if ($credentials === []) {
            return null;
        }

        $query = $this->newModelQuery();

        foreach ($credentials as $key => $value) {
            /*
             * Password tidak boleh digunakan sebagai bagian
             * dari query identity.
             *
             * Laravel akan melakukan verifikasi password
             * melalui validateCredentials().
             */
            if (str_contains((string) $key, 'password')) {
                continue;
            }

            /*
             * Hindari query dengan nilai null.
             *
             * Credential null tidak valid untuk pencarian
             * identity dan dapat menghasilkan query yang
             * tidak sesuai dengan intent authentication.
             */
            if ($value === null) {
                continue;
            }

            if (is_array($value)) {
                $query->whereIn($key, $value);

                continue;
            }

            if ($value instanceof \Arrayable) {
                $query->whereIn($key, $value->toArray());

                continue;
            }

            $query->where($key, $value);
        }

        /*
         * SECURITY / ARCHITECTURE BOUNDARY
         *
         * Jangan menambahkan:
         *
         *     ->where('tenant_id', ...)
         *
         * karena User adalah Global Identity.
         *
         * Tenant authorization harus diselesaikan melalui:
         *
         *     users
         *       -> memberships
         *       -> tenants
         *
         * pada layer Membership / Authorization Context.
         */

        return $query->first();
    }
}
