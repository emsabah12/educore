<?php

namespace Modules\Core\Application\Person;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Domain\Person\Exceptions\NikTakenException;
use Modules\Core\Domain\Person\Gender;
use RuntimeException;

/**
 * NIK KTP/KK milik Person (PRD-000 §5, OD-HR-01 & OD-AC-02 revisi 2026-10-23).
 *
 * - Disimpan terenkripsi di `person_identifiers`; dicari lewat fingerprint HMAC-SHA256 yang
 *   kuncinya diturunkan dari APP_KEY. PERHATIAN: mengganti APP_KEY membuat fingerprint lama tidak
 *   cocok lagi (pencegahan dobel tidak bekerja) sampai fingerprint dihitung ulang.
 * - Satu orang maksimal satu NIK; satu NIK hanya milik satu orang di seluruh EduCore.
 * - Nilai NIK TIDAK PERNAH masuk audit/log; pemanggil hanya mencatat nama field "nik".
 *
 * Hak akses (siapa boleh melihat/mengubah) dicek pemanggil.
 */
final class PersonNik
{
    public const TYPE = 'NIK';

    public const PATTERN = '/^[0-9]{16}$/';

    /**
     * Rapikan isian: spasi, titik, dan tanda hubung dibuang ("3201 0157 0590 0001" → "3201015705900001").
     */
    public static function normalize(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = (string) preg_replace('/[\s.\-]/', '', $value);

        return $value === '' ? null : $value;
    }

    public static function isValid(string $nik): bool
    {
        return preg_match(self::PATTERN, $nik) === 1;
    }

    /**
     * "3201015705900001" → "3201 •••• •••• 0001".
     */
    public static function mask(string $nik): string
    {
        return substr($nik, 0, 4).' •••• •••• '.substr($nik, -4);
    }

    /**
     * Peringatan (bukan penolakan) bila digit tanggal lahir di NIK (digit 7–12: DDMMYY, tanggal +40
     * untuk perempuan) tidak cocok dengan tanggal lahir / jenis kelamin yang diisi. [ASUMSI]
     * Data yang belum diisi tidak diperiksa.
     */
    public static function mismatch(string $nik, ?string $birthDate, ?Gender $gender): ?string
    {
        if (! self::isValid($nik)) {
            return null;
        }

        $day = (int) substr($nik, 6, 2);
        $female = $day > 40;
        $problems = [];

        if ($gender !== null && $female !== ($gender === Gender::Female)) {
            $problems[] = 'jenis kelamin';
        }

        if ($birthDate !== null && preg_match('/^\d{4}-(\d{2})-(\d{2})$/', $birthDate, $parts) === 1) {
            $expected = sprintf('%02d%02d%s', $female ? $day - 40 : $day, (int) substr($nik, 8, 2), substr($nik, 10, 2));
            $actual = $parts[2].$parts[1].substr($birthDate, 2, 2);

            if ($expected !== $actual) {
                $problems[] = 'tanggal lahir';
            }
        }

        return $problems === []
            ? null
            : 'NIK tidak cocok dengan '.implode(' dan ', $problems).'. Periksa kembali bila perlu.';
    }

    public function fingerprint(string $nik): string
    {
        return hash_hmac('sha256', self::TYPE.':'.$nik, $this->key());
    }

    /**
     * Pemilik NIK ini (ID Person), atau null.
     */
    public function ownerOf(string $nik): ?string
    {
        $owner = DB::table('person_identifiers')
            ->where('type', self::TYPE)
            ->where('value_fingerprint', $this->fingerprint($nik))
            ->value('person_id');

        return $owner === null ? null : (string) $owner;
    }

    /**
     * Pemilik beberapa NIK sekaligus (untuk impor).
     *
     * Catatan: kunci berupa NIK 16 digit menjadi int di array PHP; cari dengan isset($hasil[$nik]).
     *
     * @param  list<string>  $niks
     * @return array<array-key, string> NIK => ID Person
     */
    public function ownersOf(array $niks): array
    {
        $byFingerprint = [];

        foreach ($niks as $nik) {
            $byFingerprint[$this->fingerprint($nik)] = $nik;
        }

        if ($byFingerprint === []) {
            return [];
        }

        $owners = [];

        $rows = DB::table('person_identifiers')
            ->where('type', self::TYPE)
            ->whereIn('value_fingerprint', array_keys($byFingerprint))
            ->get(['value_fingerprint', 'person_id']);

        foreach ($rows as $row) {
            $owners[$byFingerprint[(string) $row->value_fingerprint]] = (string) $row->person_id;
        }

        return $owners;
    }

    /**
     * NIK orang ini (sudah dibuka), atau null bila belum ada / tidak bisa dibuka.
     */
    public function valueOf(string $personId): ?string
    {
        $encrypted = DB::table('person_identifiers')
            ->where('person_id', $personId)
            ->where('type', self::TYPE)
            ->value('encrypted_value');

        if ($encrypted === null) {
            return null;
        }

        try {
            return Crypt::decryptString((string) $encrypted);
        } catch (DecryptException) {
            // Kunci aplikasi berganti tanpa APP_PREVIOUS_KEYS: lebih aman tampil "belum ada" daripada error.
            return null;
        }
    }

    /**
     * Orang-orang yang sudah punya NIK di antara daftar ini.
     *
     * @param  list<string>  $personIds
     * @return array<string, true>
     */
    public function personsWithNik(array $personIds): array
    {
        if ($personIds === []) {
            return [];
        }

        $ids = DB::table('person_identifiers')
            ->where('type', self::TYPE)
            ->whereIn('person_id', $personIds)
            ->pluck('person_id')
            ->all();

        return array_fill_keys(array_map('strval', $ids), true);
    }

    /**
     * Simpan/ganti/hapus NIK orang ini. Panggil di dalam transaksi pemanggil.
     *
     * @return bool true bila ada perubahan
     *
     * @throws NikTakenException NIK sudah milik orang lain
     */
    public function assign(string $personId, ?string $nik): bool
    {
        if ($nik === null) {
            return DB::table('person_identifiers')->where('person_id', $personId)->where('type', self::TYPE)->delete() > 0;
        }

        if (! self::isValid($nik)) {
            throw new RuntimeException('NIK harus 16 angka; validasi di pemanggil terlewat.');
        }

        $fingerprint = $this->fingerprint($nik);
        $owner = $this->ownerOf($nik);

        if ($owner !== null && $owner !== $personId) {
            throw new NikTakenException($owner);
        }

        if ($owner === $personId) {
            return false;
        }

        $now = CarbonImmutable::now();

        try {
            // Transaksi bersarang = savepoint: bila bentrok, transaksi pemanggil tetap bisa dipakai.
            DB::transaction(function () use ($personId, $nik, $fingerprint, $now): void {
                $updated = DB::table('person_identifiers')
                    ->where('person_id', $personId)
                    ->where('type', self::TYPE)
                    ->update(['encrypted_value' => Crypt::encryptString($nik), 'value_fingerprint' => $fingerprint, 'updated_at' => $now]);

                if ($updated === 0) {
                    DB::table('person_identifiers')->insert([
                        'id' => (string) Str::uuid7(),
                        'person_id' => $personId,
                        'type' => self::TYPE,
                        'encrypted_value' => Crypt::encryptString($nik),
                        'value_fingerprint' => $fingerprint,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            });
        } catch (UniqueConstraintViolationException) {
            // Dua admin menyimpan NIK yang sama pada saat bersamaan.
            throw new NikTakenException($this->ownerOf($nik) ?? '');
        }

        return true;
    }

    private function key(): string
    {
        $appKey = (string) config('app.key');

        if (str_starts_with($appKey, 'base64:')) {
            $appKey = (string) base64_decode(substr($appKey, 7), true);
        }

        if ($appKey === '') {
            throw new RuntimeException('APP_KEY belum diatur.');
        }

        // Kunci turunan: fingerprint tidak memakai kunci enkripsi secara langsung.
        return hash_hmac('sha256', 'educore-person-identifier-fingerprint', $appKey, true);
    }
}
