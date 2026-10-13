<?php

namespace Modules\HR\Application\Employee;

use Illuminate\Support\Str;

/**
 * Saringan daftar pegawai (PRD-001 §6). Nilai yang tidak sah diabaikan (dianggap kosong),
 * sehingga URL yang diketik asal tidak menghasilkan error.
 */
final readonly class EmployeeFilters
{
    public const STATUS_ACTIVE = 'aktif';

    public const STATUS_ENDED = 'berhenti';

    public const STATUS_ALL = 'semua';

    private const SEARCH_MAX_LENGTH = 100;

    public function __construct(
        public string $search = '',
        public string $status = self::STATUS_ACTIVE,
        public ?string $organizationId = null,
        public ?string $employmentTypeId = null,
        public ?string $classificationId = null,
        public ?string $positionId = null,
        public int $perPage = EmployeeDirectory::DEFAULT_PER_PAGE,
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
            employmentTypeId: self::uuid($input['jenis'] ?? null),
            classificationId: self::uuid($input['status_kepegawaian'] ?? null),
            positionId: self::uuid($input['jabatan'] ?? null),
            perPage: ctype_digit($perPage) ? max(1, min(EmployeeDirectory::MAX_PER_PAGE, (int) $perPage)) : EmployeeDirectory::DEFAULT_PER_PAGE,
        );
    }

    /**
     * Nilai saringan untuk dikirim kembali ke form (string kosong = tidak dipakai).
     *
     * @return array{q: string, status: string, lembaga: string, jenis: string, status_kepegawaian: string, jabatan: string}
     */
    public function toArray(): array
    {
        return [
            'q' => $this->search,
            'status' => $this->status,
            'lembaga' => $this->organizationId ?? '',
            'jenis' => $this->employmentTypeId ?? '',
            'status_kepegawaian' => $this->classificationId ?? '',
            'jabatan' => $this->positionId ?? '',
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
