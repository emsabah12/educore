<?php

namespace Modules\Core\Application\Identity;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Modules\Core\Application\Audit\AuditAction;
use Modules\Core\Application\Audit\AuditLogger;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Person\Person;

/**
 * Membuat akun baru: Person + User dalam satu transaksi (PRD-000 §5, [LAMA] §14), tercatat di audit log.
 *
 * Dipakai perintah `educore:create-admin` / `educore:create-superadmin` untuk akun pertama di
 * production (OD-19). Halaman kelola anggota nanti memakai service yang sama.
 */
final class CreateAccount
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Aturan validasi akun baru. Kata sandi mengikuti Password::defaults()
     * (di production: min. 12 karakter, huruf besar-kecil, angka, simbol, belum pernah bocor).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'username' => ['nullable', 'string', 'regex:/^[a-z0-9._-]{3,50}$/', Rule::unique('users', 'username')],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];
    }

    /**
     * @param  array{name: string, email: string, username: string|null, password: string, password_confirmation: string}  $input
     *
     * @throws ValidationException
     */
    public function handle(array $input, bool $superadmin = false): User
    {
        $input['email'] = Str::lower(trim($input['email']));
        $input['username'] = $input['username'] === null || trim($input['username']) === '' ? null : Str::lower(trim($input['username']));
        $input['name'] = trim($input['name']);

        Validator::make($input, self::rules(), [
            'username.regex' => 'Username hanya boleh huruf kecil, angka, titik, garis bawah, dan tanda "-", panjang 3–50 karakter.',
        ], [
            'name' => 'nama lengkap',
            'email' => 'email',
            'username' => 'username',
            'password' => 'kata sandi',
        ])->validate();

        return DB::transaction(function () use ($input, $superadmin): User {
            $person = Person::query()->create(['name' => $input['name']]);

            $user = User::query()->create([
                'person_id' => $person->id,
                'email' => $input['email'],
                'username' => $input['username'],
                'password' => $input['password'],
            ]);

            if ($superadmin) {
                // Sengaja tidak lewat mass assignment (lihat User::$fillable).
                $user->is_superadmin = true;
                $user->save();
            }

            $this->audit->record(
                AuditAction::ACCOUNT_CREATED,
                AuditLogger::SUBJECT_USER,
                $user->id,
                ['email' => $user->email, 'username' => $user->username, 'superadmin' => $superadmin],
                global: true,
            );

            return $user;
        });
    }

    /**
     * Jadikan akun yang sudah ada sebagai Superadmin platform.
     */
    public function grantSuperadmin(User $user): void
    {
        if ($user->is_superadmin) {
            return;
        }

        DB::transaction(function () use ($user): void {
            $user->is_superadmin = true;
            $user->save();

            $this->audit->record(AuditAction::SUPERADMIN_GRANTED, AuditLogger::SUBJECT_USER, $user->id, global: true);
        });
    }
}
