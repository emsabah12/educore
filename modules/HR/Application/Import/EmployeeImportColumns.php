<?php

namespace Modules\HR\Application\Import;

/**
 * Kolom file impor pegawai (PRD-001 §6.2). Urutan di sini = urutan kolom di template.
 *
 * - key      : judul kolom di baris pertama file (dibandingkan tanpa beda huruf besar/kecil,
 *              spasi dianggap "_", tanda "*" diabaikan)
 * - required : wajib diisi di setiap baris
 * - text     : di template diberi format sel Teks, agar nol di depan tidak hilang
 */
final class EmployeeImportColumns
{
    public const NAME = 'nama';

    public const NIK = 'nik';

    public const GENDER = 'jenis_kelamin';

    public const BIRTH_PLACE = 'tempat_lahir';

    public const BIRTH_DATE = 'tanggal_lahir';

    public const PHONE = 'no_hp';

    public const ADDRESS = 'alamat';

    public const EMPLOYEE_NUMBER = 'nomor_induk';

    public const NUPTK = 'nuptk';

    public const EDUCATION = 'pendidikan';

    public const MAJOR = 'jurusan';

    public const EMPLOYMENT_TYPE = 'jenis_pegawai';

    public const CLASSIFICATION = 'status_kepegawaian';

    public const START_DATE = 'tanggal_mulai';

    public const ORGANIZATION = 'kode_lembaga';

    public const EXTRA_ORGANIZATIONS = 'lembaga_tambahan';

    public const POSITION = 'jabatan';

    /** Batas lembaga tambahan per baris. [ASUMSI] */
    public const EXTRA_ORGANIZATIONS_MAX = 10;

    /**
     * @return list<array{key: string, label: string, required: bool, text: bool, hint: string}>
     */
    public static function all(): array
    {
        return [
            self::column(self::NAME, 'Nama lengkap', true, true, 'Maksimal 200 karakter.'),
            self::column(self::NIK, 'NIK', true, true, '16 angka sesuai KTP/KK, unik. Isi sebagai teks agar digitnya tidak berubah.'),
            self::column(self::GENDER, 'Jenis kelamin', true, true, 'L atau P.'),
            self::column(self::BIRTH_PLACE, 'Tempat lahir', false, true, 'Maksimal 100 karakter.'),
            self::column(self::BIRTH_DATE, 'Tanggal lahir', false, false, 'Tanggal Excel, YYYY-MM-DD, atau DD/MM/YYYY.'),
            self::column(self::PHONE, 'Nomor HP', false, true, '8–19 angka, boleh diawali +. Isi sebagai teks agar 0 di depan tidak hilang.'),
            self::column(self::ADDRESS, 'Alamat', false, true, 'Maksimal 500 karakter.'),
            self::column(self::EMPLOYEE_NUMBER, 'Nomor induk', false, true, 'Huruf, angka, titik, garis miring, "-"; maks. 30 karakter; unik.'),
            self::column(self::NUPTK, 'NUPTK', false, true, '16 angka, unik. Isi sebagai teks.'),
            self::column(self::EDUCATION, 'Pendidikan terakhir', false, true, 'Kode pendidikan (lembar Kode).'),
            self::column(self::MAJOR, 'Program studi/jurusan', false, true, 'Maksimal 150 karakter.'),
            self::column(self::EMPLOYMENT_TYPE, 'Jenis pegawai', true, true, 'Kode jenis pegawai yang aktif (lembar Kode).'),
            self::column(self::CLASSIFICATION, 'Status kepegawaian', false, true, 'Kode status kepegawaian yang aktif (lembar Kode); boleh kosong.'),
            self::column(self::START_DATE, 'Tanggal mulai', true, false, 'Tidak boleh di masa depan. Format seperti tanggal lahir.'),
            self::column(self::ORGANIZATION, 'Lembaga penempatan', true, true, 'Kode lembaga yang aktif (lembar Kode). Menjadi penempatan utama.'),
            self::column(self::EXTRA_ORGANIZATIONS, 'Lembaga tambahan', false, true, 'Boleh kosong. Kode lembaga lain tempat pegawai juga bertugas, dipisah koma (mis. U1-MDA, U1-QURAN). Jabatan dan tanggal mulai sama dengan lembaga utama.'),
            self::column(self::POSITION, 'Jabatan', true, true, 'Kode jabatan yang aktif (lembar Kode).'),
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
     * "Tanggal Lahir *" → "tanggal_lahir".
     */
    public static function normalizeHeader(string $header): string
    {
        $header = mb_strtolower(trim(str_replace('*', '', $header)));

        return (string) preg_replace('/[\s\-]+/', '_', $header);
    }

    /**
     * @return array{key: string, label: string, required: bool, text: bool, hint: string}
     */
    private static function column(string $key, string $label, bool $required, bool $text, string $hint): array
    {
        return ['key' => $key, 'label' => $label, 'required' => $required, 'text' => $text, 'hint' => $hint];
    }
}
