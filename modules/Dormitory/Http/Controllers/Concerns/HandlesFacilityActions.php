<?php

namespace Modules\Dormitory\Http\Controllers\Concerns;

use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\Dormitory\Domain\Facility\Exceptions\FacilityException;
use Modules\Dormitory\Domain\Mutation\Exceptions\MutationException;
use Modules\Dormitory\Domain\Placement\Exceptions\PlacementException;
use Modules\Dormitory\Domain\Supervision\Exceptions\SupervisionException;
use Modules\Dormitory\Domain\YearEnd\YearEndException;

/**
 * Bantuan bersama controller fasilitas asrama: yayasan aktif, pesan sukses, dan pelanggaran aturan sebagai pesan
 * di isian form terkait (bukan galat 500).
 */
trait HandlesFacilityActions
{
    private function tenantId(): string
    {
        return app(TenantContext::class)->require()->tenantId;
    }

    /** Membership petugas yang sedang bekerja (pencatat penempatan). */
    private function actorId(): string
    {
        return app(TenantContext::class)->require()->membershipId;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $action
     * @return T
     */
    private function attempt(callable $action): mixed
    {
        try {
            return $action();
        } catch (FacilityException|PlacementException|SupervisionException|MutationException|YearEndException $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        }
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }

    /**
     * @return array<string, string>
     */
    private function facilityMessages(): array
    {
        return [
            'code.required' => 'Kode wajib diisi.',
            'name.required' => 'Nama wajib diisi.',
            'organization_id.required' => 'Pilih lembaga pemilik.',
            'gender.required' => 'Pilih putra atau putri.',
        ];
    }
}
