<?php

namespace Modules\Academic\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;
use Modules\Academic\Application\Classroom\MyClasses;
use Modules\Academic\Application\Guardian\MyChildren;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\Core\Support\BusinessClock;

/**
 * Anak saya (PRD-002 §21.2, AC-7a): orang tua/wali melihat data anaknya, hanya baca.
 * Akses dari tautan orang tua/wali; siswa yang bukan anaknya → 404.
 */
final class MyChildrenController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly MyChildren $children,
    ) {}

    public function index(): Response
    {
        $context = $this->tenantContext->require();

        return Inertia::render('academic/my-children/index', [
            'children' => $this->children->list($context->tenantId, $context->membershipId),
        ]);
    }

    public function show(string $student): Response
    {
        $context = $this->tenantContext->require();
        $detail = $this->children->detail($context->tenantId, $context->membershipId, $student);

        if ($detail === null) {
            abort(404);
        }

        return Inertia::render('academic/my-children/show', [
            ...$detail,
            // Pilihan anak lain (pindah cepat antar-anak).
            'siblings' => array_map(fn (array $child): array => ['id' => $child['id'], 'name' => $child['name']], $this->children->list($context->tenantId, $context->membershipId)),
            'days' => MyClasses::days(),
            'today' => BusinessClock::todayString(),
        ]);
    }
}
