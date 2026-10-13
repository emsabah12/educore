<?php

namespace Modules\Core\Application\Identity;

/**
 * Hasil pembuatan/pengaturan ulang kata sandi sementara. Hanya hidup di memori satu request
 * untuk ditampilkan SEKALI ke admin; tidak pernah disimpan atau dicatat (PRD-000 §4.7).
 */
final readonly class IssuedPassword
{
    public function __construct(
        public string $userId,
        public string $login,
        public string $password,
    ) {}

    /**
     * @return array{login: string, password: string}
     */
    public function toFlash(): array
    {
        return ['login' => $this->login, 'password' => $this->password];
    }
}
