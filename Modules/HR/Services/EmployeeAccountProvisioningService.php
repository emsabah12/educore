<?php

declare(strict_types=1);

namespace Modules\HR\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Identity\Models\User;
use Modules\HR\Exceptions\EmployeeAccountConflictException;

/**
 * §Pengaturan Akun Pegawai — sebelum ini, satu-satunya jalur
 * pembuatan User (kredensial login) di seluruh codebase adalah saat
 * provisioning tenant baru (admin pertama). Pegawai yang dibuat lewat
 * "Tambah Pegawai" (WorkspaceEmployeeProvisioningService) mendapat
 * Person + Membership + Employee yang SUDAH terhubung otomatis, tapi
 * TIDAK PERNAH mendapat User — mereka tidak bisa login sama sekali
 * sampai admin menjalankan alur ini.
 *
 * Password di-generate acak di server (Str::password) dan HANYA
 * dikembalikan SEKALI di response pemanggil langsung — tidak pernah
 * disimpan di log, audit trail, atau tabel manapun selain kolom
 * `password` (yang otomatis di-hash lewat cast model User).
 */
final class EmployeeAccountProvisioningService
{
    /**
     * @return array{user_id: string, email: string, generated_password: string}
     */
    public function createAccountForEmployee(
        string $personId,
        string $email,
    ): array {
        return DB::transaction(function () use ($personId, $email): array {
            $alreadyHasAccount = User::query()
                ->where('person_id', $personId)
                ->exists();

            if ($alreadyHasAccount) {
                throw new EmployeeAccountConflictException(
                    'This Employee already has a login account.',
                );
            }

            $generatedPassword = Str::password(
                length: 16,
            );

            $user = User::query()->create([
                'person_id' => $personId,
                'email' => $email,
                // `password` di-cast 'hashed' pada model User — TIDAK
                // PERNAH panggil Hash::make() manual di sini, supaya
                // tidak ada risiko hash ganda (pola sama seperti
                // TenantProvisioningService::provisionWithNewAdmin()).
                'password' => $generatedPassword,
            ]);

            return [
                'user_id' => (string) $user->id,
                'email' => $user->email,
                'generated_password' => $generatedPassword,
            ];
        });
    }
}
