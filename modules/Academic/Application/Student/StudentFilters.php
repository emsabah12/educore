<?php

namespace Modules\Academic\Application\Student;

use Illuminate\Support\Str;

/**
 * Saringan daftar siswa (PRD-002 §6). Nilai yang tidak sah diabaikan (dianggap kosong),
 * sehingga URL yang diketik asal tidak menghasilkan error.
 *
 * Status dihitung terhadap lembaga yang terlihat (dan saringan lembaga, bila dipakai):
 *   aktif  = punya pendaftaran terbuka di sana;
 *   keluar = pernah terdaftar di sana, tetapi tidak ada lagi pendaftaran yang terbuka (lulus/pindah/…).
 */
final readonly class StudentFilters
{
    public const STATUS_ACTIVE = 'aktif';

    public const STATUS_ENDED = 'keluar';

    public const STATUS_ALL = 'semua';

    public const DEFAULT_PER_PAGE = 25;

    /** PRD-000 §9: maksimal 100 baris per halaman. */
    public const MAX_PER_PAGE = 100;

    private const SEARCH_MAX_LENGTH = 100;

    public function __construct(
        public string $search = '',
        public string $status = self::STATUS_ACTIVE,
        public ?string $organizationId = null,
        public int $perPage = self::DEFAULT_PER_PAGE,
    ) {}

    /**
     * @param  array<string, mixed>  $input  mis. query string
     */
    public static function fromArray(array $input): self
    {
        $status = self::text($input['status'] ?? null);
        $perPage = self::text($input['per_page'] ?? null);

        return new self(
            search: mb_substr(self::text($input['q'] ?? null), 0, self::SEARCH_MAX_LENGTH),
            status: in_array($status, [self::STATUS_ACTIVE, self::STATUS_ENDED, self::STATUS_ALL], true) ? $status : self::STATUS_ACTIVE,
            organizationId: self::uuid($input['lembaga'] ?? null),
            perPage: ctype_digit($perPage) ? max(1, min(self::MAX_PER_PAGE, (int) $perPage)) : self::DEFAULT_PER_PAGE,
        );
    }

    /**
     * Nilai saringan untuk dikirim kembali ke form (string kosong = tidak dipakai).
     *
     * @return array{q: string, status: string, lembaga: string}
     */
    public function toArray(): array
    {
        return [
            'q' => $this->search,
            'status' => $this->status,
            'lembaga' => $this->organizationId ?? '',
        ];
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private static function uuid(mixed $value): ?string
    {
        $value = self::text($value);

        return Str::isUuid($value) ? strtolower($value) : null;
    }
}
