<?php

namespace Modules\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Modules\Core\Application\Identity\CreateAccount;
use Modules\Core\Console\Concerns\AsksForAccount;
use Modules\Core\Domain\Identity\User;

/**
 * Membuat akun Superadmin platform (OD-19). Superadmin mengurus platform, bukan data
 * yayasan (OD-12); untuk mengelola yayasan pakai `educore:create-admin`.
 */
final class CreateSuperadminCommand extends Command
{
    use AsksForAccount;

    protected $signature = 'educore:create-superadmin';

    protected $description = 'Buat akun Superadmin platform, atau jadikan akun yang sudah ada sebagai Superadmin';

    public function handle(CreateAccount $createAccount): int
    {
        $email = $this->askEmail();
        $existing = $email === '' ? null : User::query()->where('email', $email)->first();

        if ($existing instanceof User) {
            if ($existing->is_superadmin) {
                $this->components->info("Akun {$email} sudah Superadmin. Tidak ada yang diubah.");

                return self::SUCCESS;
            }

            if (! $this->confirm("Akun {$email} sudah ada. Jadikan Superadmin?")) {
                $this->components->warn('Dibatalkan.');

                return self::FAILURE;
            }

            $createAccount->grantSuperadmin($existing);
            $this->components->info("Akun {$email} sekarang Superadmin.");

            return self::SUCCESS;
        }

        try {
            $user = $createAccount->handle($this->askNewAccount($email), superadmin: true);
        } catch (ValidationException $exception) {
            return $this->reportValidation($exception);
        }

        $this->components->info("Superadmin {$user->email} berhasil dibuat.");

        return self::SUCCESS;
    }
}
