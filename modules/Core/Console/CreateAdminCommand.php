<?php

namespace Modules\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Application\Authorization\CoreAccess;
use Modules\Core\Application\Authorization\ManageRoles;
use Modules\Core\Application\Identity\CreateAccount;
use Modules\Core\Application\Membership\GrantMembership;
use Modules\Core\Console\Concerns\AsksForAccount;
use Modules\Core\Domain\Authorization\Exceptions\AccessException;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Tenancy\Tenant;

/**
 * Membuat Admin Yayasan pertama (OD-19): akun + keanggotaan + role tenant-wide `admin-yayasan`,
 * semuanya dalam satu transaksi dan tercatat di audit log. Bila email sudah terdaftar,
 * akun yang ada dijadikan Admin Yayasan (setelah konfirmasi).
 */
final class CreateAdminCommand extends Command
{
    use AsksForAccount;

    protected $signature = 'educore:create-admin {tenant=YAYASAN : Kode yayasan}';

    protected $description = 'Buat Admin Yayasan, atau jadikan akun yang sudah ada sebagai Admin Yayasan';

    public function handle(CreateAccount $createAccount, GrantMembership $grantMembership, ManageRoles $manageRoles): int
    {
        $code = Str::upper(trim((string) $this->argument('tenant')));
        $tenant = Tenant::query()->where('code', $code)->first();

        if (! $tenant instanceof Tenant) {
            $known = Tenant::query()->orderBy('code')->pluck('code')->implode(', ');
            $this->components->error("Yayasan dengan kode {$code} tidak ditemukan.".($known === '' ? ' Belum ada yayasan; jalankan seeder yayasan dulu.' : " Kode yang ada: {$known}."));

            return self::FAILURE;
        }

        $email = $this->askEmail();
        $existing = $email === '' ? null : User::query()->where('email', $email)->first();

        // Tentukan dulu "cara mendapatkan akun" di luar transaksi (pertanyaan interaktif
        // tidak boleh terjadi di dalam transaksi). Akun baru baru benar-benar dibuat di
        // dalam transaksi, supaya gagal memasang role = akun juga batal dibuat.
        if ($existing instanceof User) {
            if (! $this->confirm("Akun {$email} sudah ada. Jadikan Admin Yayasan di {$tenant->name}?")) {
                $this->components->warn('Dibatalkan.');

                return self::FAILURE;
            }

            $resolveUser = fn (): User => $existing;
        } else {
            $input = $this->askNewAccount($email);
            $resolveUser = fn (): User => $createAccount->handle($input);
        }

        try {
            $user = DB::transaction(function () use ($resolveUser, $tenant, $grantMembership, $manageRoles): User {
                $user = $resolveUser();

                $membership = $grantMembership->handle($user->person_id, $tenant->id);
                $manageRoles->grantTenantRole($membership, CoreAccess::ROLE_ADMIN_YAYASAN);

                return $user;
            });
        } catch (ValidationException $exception) {
            return $this->reportValidation($exception);
        } catch (AccessException $exception) {
            $this->components->error($exception->getMessage().' Perintah: php artisan educore:sync-access');

            return self::FAILURE;
        }

        $this->components->info("{$user->email} sekarang Admin Yayasan di {$tenant->name}.");

        return self::SUCCESS;
    }
}
