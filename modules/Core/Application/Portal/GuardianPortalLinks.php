<?php

namespace Modules\Core\Application\Portal;

/**
 * Tombol tambahan di halaman anak pada portal orang tua/wali "Anak saya" (PRD-002 §21), didaftarkan modul
 * saat boot — mis. Finance: "Tagihan" (PRD-003 FN-5). Halaman Anak saya milik Academic, tetapi Academic tidak
 * perlu tahu modul pemilik tombol (PRD-003 §7).
 *
 * Alamat tombol = "/anak-saya/{id siswa}/{suffix}". Modul pemilik halaman tujuan wajib memeriksa sendiri
 * tautan orang tua/wali (404 bila bukan anaknya).
 *
 * @phpstan-type PortalLink array{key: string, label: string, suffix: string, icon: string}
 */
final class GuardianPortalLinks
{
    /** @var array<string, PortalLink> */
    private array $links = [];

    /**
     * @param  string  $icon  nama ikon yang dikenali frontend (mis. "receipt")
     */
    public function register(string $key, string $label, string $suffix, string $icon): self
    {
        $this->links[$key] = ['key' => $key, 'label' => $label, 'suffix' => $suffix, 'icon' => $icon];

        return $this;
    }

    /**
     * @return list<PortalLink> urut pendaftaran
     */
    public function all(): array
    {
        return array_values($this->links);
    }
}
