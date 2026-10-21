<?php

namespace Modules\Academic\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;
use Modules\Academic\Application\Classroom\MyClasses;
use Modules\Core\Domain\Tenancy\TenantContext;

/**
 * Menu "Kelas saya" (PRD-002 §16 AC-3d): kelas yang dipegang wali kelas/guru pengampu yang sedang
 * login, siswanya, dan jadwal mengajarnya. Hanya dibaca.
 *
 * Akses ditentukan oleh data penugasan mengajar, bukan permission: kelas yang tidak ia pegang → 404.
 */
final class MyClassesController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(MyClasses $myClasses): Response
    {
        $context = $this->tenantContext->require();
        $employeeId = $myClasses->employeeId($context->tenantId, $context->membershipId);
        $classes = $employeeId === null ? [] : $myClasses->classes($context->tenantId, $employeeId);

        return Inertia::render('academic/my-classes/index', [
            'linked' => $employeeId !== null,
            'classes' => $classes,
            'schedule' => $employeeId === null ? [] : $myClasses->schedule($employeeId, array_column($classes, 'id')),
            'days' => MyClasses::days(),
        ]);
    }

    public function show(string $class, MyClasses $myClasses): Response
    {
        $context = $this->tenantContext->require();
        $employeeId = $myClasses->employeeId($context->tenantId, $context->membershipId);
        $picked = $employeeId === null ? null : MyClasses::pick($myClasses->classes($context->tenantId, $employeeId), $class);

        if ($picked === null) {
            abort(404);
        }

        return Inertia::render('academic/my-classes/show', [
            'class' => $picked,
            'students' => $myClasses->students($picked['id'], $picked['homeroom']),
        ]);
    }
}
