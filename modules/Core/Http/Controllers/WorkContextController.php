<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Application\Context\WorkContextResolver;
use Modules\Core\Application\Context\WorkContextSession;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Organization\OrganizationalAssignment;
use Modules\Core\Domain\Tenancy\Membership;
use Modules\Core\Domain\Tenancy\WorkContext;

/**
 * Halaman pemilihan konteks kerja: yayasan dan lembaga kerja (PRD-000 §6).
 *
 * Pilihan yang dikirim browser hanya diterima bila ada di daftar milik pengguna
 * sendiri; selain itu dijawab 404 agar keberadaan data orang lain tidak bocor.
 */
final class WorkContextController
{
    public function __construct(
        private readonly WorkContextResolver $resolver,
    ) {}

    public function editTenant(Request $request): Response|RedirectResponse
    {
        $memberships = $this->resolver->memberships($this->user($request));

        // Satu atau nol yayasan tidak perlu dipilih; biarkan middleware yang mengarahkan.
        if ($memberships->count() <= 1) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('context/select-tenant', [
            'memberships' => $memberships
                ->map(fn (Membership $membership): array => [
                    'id' => $membership->id,
                    'tenant_name' => $membership->tenant->name,
                ])
                ->all(),
            'currentMembershipId' => $request->session()->get(WorkContextSession::MEMBERSHIP),
        ]);
    }

    public function updateTenant(Request $request): RedirectResponse
    {
        $membershipId = $request->input('membership_id');

        $membership = $this->resolver
            ->memberships($this->user($request))
            ->firstWhere('id', is_string($membershipId) ? $membershipId : null);

        if (! $membership instanceof Membership) {
            abort(404);
        }

        // Yayasan baru → lembaga kerja harus ditentukan ulang.
        $request->session()->put(WorkContextSession::MEMBERSHIP, $membership->id);
        $request->session()->forget(WorkContextSession::WORKSPACE);

        return redirect()->route('dashboard');
    }

    public function editWorkspace(Request $request): Response|RedirectResponse
    {
        $membership = $this->resolver->currentMembership($this->user($request), $request->session());

        if ($membership === null) {
            return redirect()->route('dashboard');
        }

        $assignments = $this->resolver->assignments($membership);
        $offersTenant = $this->resolver->offersTenantWorkspace($membership, $assignments);

        if ($assignments->count() + ($offersTenant ? 1 : 0) <= 1) {
            return redirect()->route('dashboard');
        }

        $options = $assignments
            ->map(fn (OrganizationalAssignment $assignment): array => [
                'id' => $assignment->id,
                'label' => WorkContext::labelFor($assignment->organization?->name, $assignment->jenjang_filter),
                'path' => $assignment->organization === null ? null : $this->resolver->pathOf($assignment->organization),
                'type' => $assignment->isFunctional() ? WorkContext::WORKSPACE_FUNCTIONAL : WorkContext::WORKSPACE_ORGANIZATION,
            ])
            ->values()
            ->all();

        if ($offersTenant) {
            // Pemilik role tenant-wide juga boleh bekerja di Seluruh Yayasan (PRD-000 §6).
            array_unshift($options, [
                'id' => WorkContext::WORKSPACE_TENANT,
                'label' => 'Seluruh Yayasan',
                'path' => null,
                'type' => WorkContext::WORKSPACE_TENANT,
            ]);
        }

        return Inertia::render('context/select-workspace', [
            'tenantName' => $membership->tenant->name,
            'assignments' => $options,
            'currentAssignmentId' => $request->session()->get(WorkContextSession::WORKSPACE),
        ]);
    }

    public function updateWorkspace(Request $request): RedirectResponse
    {
        $membership = $this->resolver->currentMembership($this->user($request), $request->session());

        if (! $membership instanceof Membership) {
            abort(404);
        }

        $assignmentId = $request->input('assignment_id');
        $assignments = $this->resolver->assignments($membership);

        // "Seluruh Yayasan" hanya diterima bila memang termasuk pilihan pengguna ini.
        if ($assignmentId === WorkContext::WORKSPACE_TENANT && $this->resolver->offersTenantWorkspace($membership, $assignments)) {
            $request->session()->put(WorkContextSession::WORKSPACE, WorkContext::WORKSPACE_TENANT);

            return redirect()->route('dashboard');
        }

        $assignment = $assignments->firstWhere('id', is_string($assignmentId) ? $assignmentId : null);

        if (! $assignment instanceof OrganizationalAssignment) {
            abort(404);
        }

        $request->session()->put(WorkContextSession::WORKSPACE, $assignment->id);

        return redirect()->route('dashboard');
    }

    public function unregistered(Request $request): Response|RedirectResponse
    {
        // Bila ternyata sudah punya membership, tidak perlu di halaman ini.
        if ($this->resolver->memberships($this->user($request))->isNotEmpty()) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('context/unregistered');
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        return $user;
    }
}
