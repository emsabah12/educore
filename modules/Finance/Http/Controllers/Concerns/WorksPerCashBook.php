<?php

namespace Modules\Finance\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Organization\OrganizationStatus;
use Modules\Core\Domain\Organization\OrganizationType;
use Modules\Finance\Application\Authorization\FinanceAccess;

/**
 * Bantuan bersama halaman kas (PRD-004 §5–§6): kas = satu LEMBAGA atau kas Yayasan (`yayasan`, organization_id
 * kosong). Kas lembaga memakai izin di lembaga itu; kas Yayasan memakai izin tingkat Yayasan.
 *
 * Kelas pemakai wajib punya properti `$authorization` (AuthorizationService).
 *
 * @property-read AuthorizationService $authorization
 */
trait WorksPerCashBook
{
    /** Nilai pilihan kas untuk kas Yayasan. */
    public const YAYASAN_KEY = 'yayasan';

    /**
     * Pilihan kas yang boleh dilihat: kas Yayasan (izin lihat kas tingkat Yayasan) lalu lembaga urut nama.
     *
     * @return list<array{value: string, label: string}>
     */
    private function books(): array
    {
        $books = [];

        if ($this->allowsAt(null, FinanceAccess::CASH_VIEW)) {
            $books[] = ['value' => self::YAYASAN_KEY, 'label' => 'Kas Yayasan'];
        }

        foreach (Organization::query()
            ->whereIn('id', $this->authorization->visibleNodeIds(FinanceAccess::CASH_VIEW))
            ->where('type', OrganizationType::Lembaga->value)
            ->where('status', OrganizationStatus::Active->value)
            ->orderBy('name')
            ->get() as $organization) {
            $books[] = ['value' => $organization->id, 'label' => $organization->name];
        }

        return $books;
    }

    /**
     * Kas dari isian `kas` (id lembaga atau "yayasan"), sesudah cek hak akses.
     */
    private function bookFromRequest(Request $request, string $permission): ?string
    {
        $key = $request->input('kas');

        if (! is_string($key) || $key === '') {
            abort(404);
        }

        if ($key === self::YAYASAN_KEY) {
            $this->authorizeBook(null, $permission);

            return null;
        }

        if (! Str::isUuid($key) || ! Organization::query()->whereKey($key)->where('type', OrganizationType::Lembaga->value)->exists()) {
            abort(404);
        }

        $this->authorizeBook($key, $permission);

        return $key;
    }

    /**
     * Kas di luar cakupan lihat → 404; terlihat tetapi tanpa $permission → 403.
     */
    private function authorizeBook(?string $organizationId, string $permission): void
    {
        if (! $this->allowsAt($organizationId, FinanceAccess::CASH_VIEW)) {
            abort(404);
        }

        if (! $this->allowsAt($organizationId, $permission)) {
            abort(403);
        }
    }

    private function allowsAt(?string $organizationId, string $permission): bool
    {
        return $organizationId === null
            ? $this->authorization->inspectTarget($permission, null, null)->allowed()
            : $this->authorization->allows($permission, $organizationId);
    }

    /**
     * Kunci pilihan kas untuk organization_id (null → "yayasan").
     */
    private static function bookKey(?string $organizationId): string
    {
        return $organizationId ?? self::YAYASAN_KEY;
    }

    /**
     * Pilihan kas dengan $permission (mis. kas yang bisa dikelola), urutan sama dengan books().
     *
     * @return list<array{value: string, label: string}>
     */
    private function booksWith(string $permission): array
    {
        $books = [];

        foreach ($this->books() as $book) {
            if ($this->allowsAt($book['value'] === self::YAYASAN_KEY ? null : $book['value'], $permission)) {
                $books[] = $book;
            }
        }

        return $books;
    }
}
