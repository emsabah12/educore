<?php

namespace Modules\Core\Console;

use Illuminate\Console\Command;
use Modules\Core\Application\Authorization\SyncAccessCatalog;

/**
 * Jalankan setiap kali ada permission/role baru di kode, termasuk saat deploy.
 */
final class SyncAccessCommand extends Command
{
    protected $signature = 'educore:sync-access';

    protected $description = 'Sinkronkan katalog role & permission dari kode ke database';

    public function handle(SyncAccessCatalog $sync): int
    {
        $result = $sync->handle();

        $this->components->info(sprintf(
            'Katalog akses tersinkron: %d permission, %d role, %d permission lama dihapus, %d role lama dikosongkan.',
            $result['permissions'],
            $result['roles'],
            $result['removed_permissions'],
            $result['emptied_roles'],
        ));

        return self::SUCCESS;
    }
}
