<?php

declare(strict_types=1);

namespace Modules\Core\Person\Contracts;

/**
 * HR-003 §9.2 — "Add a small Core contract instead of HR querying
 * person_identifiers directly."
 *
 * "HR never owns or copies the canonical Person identifier table" —
 * kontrak inilah satu-satunya pintu HR (atau modul manapun) untuk
 * menanyakan "identitas kuat ini milik Person mana?", tanpa pernah
 * menyentuh tabel `person_identifiers` secara langsung.
 */
interface PersonIdentityResolutionServiceInterface
{
    public const string STATUS_UNRESOLVED = 'UNRESOLVED';
    public const string STATUS_MATCHED_EXISTING = 'MATCHED_EXISTING';
    public const string STATUS_CONFLICT = 'CONFLICT';

    /**
     * Resolusi exact-match (§9.1: hanya strong evidence — National ID,
     * Passport, dst — yang boleh dipakai; weak evidence seperti nama/
     * email/telepon TIDAK PERNAH memicu auto-resolve, §9.3).
     *
     * Kalau beberapa klaim identitas berbeda ternyata cocok ke Person
     * yang BERBEDA, itu dilaporkan sebagai CONFLICT — bukan diam-diam
     * memilih salah satu (§9.2 poin 5: "detect inconsistent claims that
     * resolve to different Persons").
     *
     * @param list<array{type: string, issuing_country_code: string, value: string}> $strongClaims
     *
     * @return array{status: string, person_id: string|null}
     */
    public function resolveByStrongIdentifiers(array $strongClaims): array;

    /**
     * Membuat Person + identifier canonical baru — HANYA dipanggil
     * ketika alur yang berwenang secara eksplisit meminta "buat baru"
     * (§9.2 poin 6: "only when explicitly requested by an authorized
     * workflow"), BUKAN otomatis dari resolveByStrongIdentifiers().
     *
     * @return string Person ID yang baru dibuat.
     *
     * @throws \RuntimeException Jika identifier sudah dimiliki Person lain
     *                            (constraint unik DB sebagai penjaga
     *                            konkurensi terakhir, §9.2 poin 7).
     */
    public function createPersonWithIdentifier(
        string $name,
        string $type,
        string $issuingCountryCode,
        string $rawValue,
    ): string;
}
