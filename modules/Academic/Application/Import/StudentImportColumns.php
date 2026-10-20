<?php

namespace Modules\Academic\Application\Import;

use Modules\Academic\Domain\Student\GuardianRelation;

/**
 * Kolom file impor siswa (PRD-002 AC-1e). Urutan di sini = urutan kolom di template.
 *
 * - key      : judul kolom di baris pertama file (tanpa beda huruf besar/kecil; spasi = "_"; "*" diabaikan)
 * - required : wajib diisi di setiap baris
 * - text     : di template diberi format sel Teks, agar 0 di depan NISN/HP tidak hilang
 */
final class StudentImportColumns
{
    public const NAME = 'nama';

    public const GENDER = 'jenis_kelamin';

    public const BIRTH_PLACE = 'tempat_lahir';

    public const BIRTH_DATE = 'tanggal_lahir';

    public const PHONE = 'no_hp';

    public const ADDRESS = 'alamat';

    public const NISN = 'nisn';

    public const STUDENT_NUMBER = 'nomor_induk';

    public const START_DATE = 'tanggal_masuk';

    public const ENTRY_TYPE = 'jenis_masuk';

    public const CLASS_NAME = 'kelas';

    public const FATHER_NAME = 'nama_ayah';

    public const FATHER_PHONE = 'hp_ayah';

    public const MOTHER_NAME = 'nama_ibu';

    public const MOTHER_PHONE = 'hp_ibu';

    public const GUARDIAN_NAME = 'nama_wali';

    public const GUARDIAN_PHONE = 'hp_wali';

    public const PRIMARY_CONTACT = 'kontak_utama';

    /**
     * Orang tua/wali di file: hubungan => [kolom nama, kolom HP], urutan = urutan bawaan kontak utama.
     *
     * @return list<array{relation: GuardianRelation, name: string, phone: string}>
     */
    public static function guardians(): array
    {
        return [
            ['relation' => GuardianRelation::Father, 'name' => self::FATHER_NAME, 'phone' => self::FATHER_PHONE],
            ['relation' => GuardianRelation::Mother, 'name' => self::MOTHER_NAME, 'phone' => self::MOTHER_PHONE],
            ['relation' => GuardianRelation::Guardian, 'name' => self::GUARDIAN_NAME, 'phone' => self::GUARDIAN_PHONE],
        ];
    }

    /**
     * @return list<array{key: string, label: string, required: bool, text: bool, hint: string}>
     */
    public static function all(): array
    {
        return [
            self::column(self::NAME, 'Nama lengkap siswa', true, true, 'Maksimal 200 karakter.'),
            self::column(self::GENDER, 'Jenis kelamin', true, true, 'L atau P.'),
            self::column(self::BIRTH_PLACE, 'Tempat lahir', false, true, 'Maksimal 100 karakter.'),
            self::column(self::BIRTH_DATE, 'Tanggal lahir', false, false, 'Tanggal Excel, YYYY-MM-DD, atau DD/MM/YYYY.'),
            self::column(self::PHONE, 'Nomor HP siswa', false, true, '8–19 angka, boleh diawali +. Isi sebagai teks agar 0 di depan tidak hilang.'),
            self::column(self::ADDRESS, 'Alamat', false, true, 'Maksimal 500 karakter.'),
            self::column(self::NISN, 'NISN', false, true, '10 angka, isi sebagai teks. Bila NISN sudah tercatat untuk siswa dengan nama yang sama, siswa itu hanya didaftarkan ke lembaga ini.'),
            self::column(self::STUDENT_NUMBER, 'Nomor induk', false, true, 'Huruf, angka, titik, garis miring, "-"; maks. 30 karakter; tidak boleh dipakai siswa lain di lembaga ini.'),
            self::column(self::START_DATE, 'Tanggal masuk', true, false, 'Tanggal masuk ke lembaga; tidak boleh di masa depan.'),
            self::column(self::ENTRY_TYPE, 'Jenis masuk', false, true, 'BARU atau PINDAHAN; kosong = BARU.'),
            self::column(self::CLASS_NAME, 'Kelas', false, true, 'Opsional. Nama kelas aktif tahun ajaran berjalan (lihat daftar di bawah).'),
            self::column(self::FATHER_NAME, 'Nama ayah', false, true, 'Opsional, maks. 200 karakter.'),
            self::column(self::FATHER_PHONE, 'HP ayah', false, true, 'Opsional, seperti nomor HP siswa. Nama + HP sama dengan orang tua yang sudah tercatat = orang yang sama.'),
            self::column(self::MOTHER_NAME, 'Nama ibu', false, true, 'Opsional, maks. 200 karakter.'),
            self::column(self::MOTHER_PHONE, 'HP ibu', false, true, 'Opsional, seperti HP ayah.'),
            self::column(self::GUARDIAN_NAME, 'Nama wali', false, true, 'Opsional, maks. 200 karakter.'),
            self::column(self::GUARDIAN_PHONE, 'HP wali', false, true, 'Opsional, seperti HP ayah.'),
            self::column(self::PRIMARY_CONTACT, 'Kontak utama', false, true, 'AYAH, IBU, atau WALI (yang namanya diisi); kosong = orang tua pertama yang diisi.'),
        ];
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_map(fn (array $column): string => $column['key'], self::all());
    }

    /**
     * @return list<string>
     */
    public static function requiredKeys(): array
    {
        $keys = [];

        foreach (self::all() as $column) {
            if ($column['required']) {
                $keys[] = $column['key'];
            }
        }

        return $keys;
    }

    public static function label(string $key): string
    {
        foreach (self::all() as $column) {
            if ($column['key'] === $key) {
                return $column['label'];
            }
        }

        return $key;
    }

    /**
     * @return array{key: string, label: string, required: bool, text: bool, hint: string}
     */
    private static function column(string $key, string $label, bool $required, bool $text, string $hint): array
    {
        return ['key' => $key, 'label' => $label, 'required' => $required, 'text' => $text, 'hint' => $hint];
    }
}
