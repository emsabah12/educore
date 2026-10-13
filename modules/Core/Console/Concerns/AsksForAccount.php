<?php

namespace Modules\Core\Console\Concerns;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Pertanyaan bersama untuk perintah pembuatan akun.
 *
 * Kata sandi SELALU ditanyakan secara tersembunyi, tidak pernah lewat argumen/opsi,
 * supaya tidak tersimpan di riwayat terminal atau daftar proses server.
 */
trait AsksForAccount
{
    protected function askEmail(): string
    {
        $email = $this->ask('Email');

        return Str::lower(trim(is_string($email) ? $email : ''));
    }

    /**
     * @return array{name: string, email: string, username: string|null, password: string, password_confirmation: string}
     */
    protected function askNewAccount(string $email): array
    {
        $name = $this->ask('Nama lengkap');
        $username = $this->ask('Username (boleh dikosongkan)');
        $password = $this->secret('Kata sandi');
        $confirmation = $this->secret('Ulangi kata sandi');

        return [
            'name' => is_string($name) ? $name : '',
            'email' => $email,
            'username' => is_string($username) && trim($username) !== '' ? $username : null,
            'password' => is_string($password) ? $password : '',
            'password_confirmation' => is_string($confirmation) ? $confirmation : '',
        ];
    }

    protected function reportValidation(ValidationException $exception): int
    {
        foreach ($exception->validator->errors()->all() as $message) {
            $this->components->error($message);
        }

        return self::FAILURE;
    }
}
