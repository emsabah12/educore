<?php

namespace Modules\Dormitory\Application\Import;

/**
 * Kolom file impor penempatan (PRD-008 §20.2). Judul kolom dibandingkan setelah dirapikan
 * (huruf kecil, spasi/tanda hubung → "_", tanda * dibuang).
 */
final class PlacementImportColumns
{
    public const NISN = 'nisn';

    public const NIS = 'nis';

    public const BUILDING = 'kode_gedung';

    public const ROOM = 'kode_kamar';

    public const BED = 'kode_bed';

    public const LOCKER = 'kode_loker';

    public const MODE = 'jenis';

    public const DATE = 'tanggal';

    public const NOTE = 'catatan';

    /**
     * @return list<array{key: string, label: string, required: bool, text: bool, hint: string}>
     */
    public static function all(): array
    {
        return [
            ['key' => self::NISN, 'label' => 'NISN', 'required' => false, 'text' => true, 'hint' => 'Isi NISN atau NIS (minimal salah satu). NISN dipakai lebih dulu.'],
            ['key' => self::NIS, 'label' => 'NIS', 'required' => false, 'text' => true, 'hint' => 'Nomor induk di lembaga. Bila NIS sama di dua lembaga, isi NISN.'],
            ['key' => self::BUILDING, 'label' => 'Kode gedung', 'required' => true, 'text' => true, 'hint' => 'Kode gedung di asrama ini (lihat daftar di bawah).'],
            ['key' => self::ROOM, 'label' => 'Kode kamar', 'required' => true, 'text' => true, 'hint' => 'Kode kamar di gedung itu.'],
            ['key' => self::BED, 'label' => 'Kode bed', 'required' => false, 'text' => true, 'hint' => 'Kosong = dipilih otomatis berurutan.'],
            ['key' => self::LOCKER, 'label' => 'Kode loker', 'required' => false, 'text' => true, 'hint' => 'Kosong = dipilih otomatis berurutan.'],
            ['key' => self::MODE, 'label' => 'Jenis', 'required' => true, 'text' => true, 'hint' => 'LANGSUNG (langsung tinggal) atau RENCANA.'],
            ['key' => self::DATE, 'label' => 'Tanggal', 'required' => false, 'text' => false, 'hint' => 'YYYY-MM-DD. Wajib untuk LANGSUNG (tanggal masuk); opsional untuk RENCANA.'],
            ['key' => self::NOTE, 'label' => 'Catatan', 'required' => false, 'text' => true, 'hint' => 'Opsional, maks. 200 karakter.'],
        ];
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_column(self::all(), 'key');
    }

    /**
     * @return list<string>
     */
    public static function requiredKeys(): array
    {
        return array_column(array_filter(self::all(), fn (array $column): bool => $column['required']), 'key');
    }
}
