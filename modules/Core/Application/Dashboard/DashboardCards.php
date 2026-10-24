<?php

namespace Modules\Core\Application\Dashboard;

use Closure;

/**
 * Kartu ringkas di Beranda yang didaftarkan modul saat boot (mis. HR: "Dokumen akan habis", PRD-001 §15.2).
 *
 * Core tidak tahu isi kartunya: setiap modul mendaftarkan resolver yang dipanggil saat Beranda dibuka
 * (di dalam konteks kerja). Resolver mengembalikan null bila pengguna tidak berhak atau kartu tidak
 * relevan; hak akses menjadi tanggung jawab modul pemilik kartu.
 *
 * @phpstan-type DashboardCard array{
 *     key: string,
 *     title: string,
 *     value: int,
 *     description: string,
 *     href: string,
 *     tone: 'default'|'warning'|'danger'
 * }
 */
final class DashboardCards
{
    /** @var array<string, Closure(): (DashboardCard|null)> */
    private array $resolvers = [];

    /**
     * @param  Closure(): (DashboardCard|null)  $resolver
     */
    public function register(string $key, Closure $resolver): self
    {
        $this->resolvers[$key] = $resolver;

        return $this;
    }

    /**
     * Kartu yang berlaku untuk pengguna saat ini, urut pendaftaran.
     *
     * @return list<DashboardCard>
     */
    public function resolve(): array
    {
        $cards = [];

        foreach ($this->resolvers as $resolver) {
            $card = $resolver();

            if ($card !== null) {
                $cards[] = $card;
            }
        }

        return $cards;
    }
}
