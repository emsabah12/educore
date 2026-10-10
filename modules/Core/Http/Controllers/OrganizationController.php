<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Application\Authorization\CoreAccess;
use Modules\Core\Application\Context\WorkContextResolver;
use Modules\Core\Application\Organization\CreateOrganization;
use Modules\Core\Application\Organization\DeactivateOrganization;
use Modules\Core\Application\Organization\MoveOrganization;
use Modules\Core\Application\Organization\NewOrganizationData;
use Modules\Core\Application\Organization\ReactivateOrganization;
use Modules\Core\Application\Organization\RenameOrganization;
use Modules\Core\Domain\Organization\Exceptions\OrganizationTreeException;
use Modules\Core\Domain\Organization\Jenjang;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Organization\OrganizationCategory;
use Modules\Core\Domain\Organization\OrganizationStatus;
use Modules\Core\Domain\Organization\OrganizationType;
use Modules\Core\Domain\Tenancy\TenantContext;

/**
 * Halaman admin pohon lembaga (PRD-000 §4.6, OD-14).
 *
 * - Melihat: node yang terlihat dengan `core.organizations.view` di lembaga kerja aktif (§7.2).
 * - Mengelola: `core.organizations.manage` di node terkait (node + turunannya, §7.1).
 *   MENAMBAH atau MEMINDAH node ke tingkat Yayasan (tanpa induk) hanya untuk pemilik
 *   izin tingkat Yayasan. Tujuan pemindahan juga wajib di dalam izin kelola.
 * - Di luar cakupan → 404, di dalam cakupan tanpa izin → 403 (OD-13).
 */
final class OrganizationController
{
    private const CODE_RULE = 'regex:/^[A-Za-z0-9][A-Za-z0-9-]{1,49}$/';

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TenantContext $tenantContext,
        private readonly WorkContextResolver $contextResolver,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize(CoreAccess::ORGANIZATIONS_VIEW);

        $showInactive = $request->boolean('nonaktif');
        $manageable = array_flip($this->authorization->visibleNodeIds(CoreAccess::ORGANIZATIONS_MANAGE));

        $organizations = Organization::query()
            ->whereIn('id', $this->authorization->visibleNodeIds(CoreAccess::ORGANIZATIONS_VIEW))
            ->when(! $showInactive, fn ($query) => $query->where('status', OrganizationStatus::Active->value))
            ->orderBy('code')
            ->get();

        $shown = $organizations->pluck('id')->flip();

        $nodes = $organizations
            ->map(function (Organization $organization) use ($shown, $manageable): array {
                // Induk yang tidak terlihat (di luar cakupan) tidak ditampilkan; node ini menjadi
                // akar di tampilan, dengan keterangan jalurnya.
                $parentShown = $organization->parent_id !== null && $shown->has($organization->parent_id);

                return [
                    'id' => $organization->id,
                    'parent_id' => $parentShown ? $organization->parent_id : null,
                    'path' => $parentShown ? null : $this->contextResolver->pathOf($organization),
                    'code' => $organization->code,
                    'name' => $organization->name,
                    'type' => $organization->type->value,
                    'type_label' => $organization->type->label(),
                    'category_label' => $organization->category?->label(),
                    'jenjang_label' => $organization->jenjang?->label(),
                    'is_active' => $organization->isActive(),
                    'can_manage' => isset($manageable[$organization->id]),
                ];
            })
            ->values()
            ->all();

        return Inertia::render('organizations/index', [
            'nodes' => $nodes,
            'showInactive' => $showInactive,
            'canCreateRoot' => $this->canManageRoot(),
            'options' => [
                'types' => $this->options(OrganizationType::cases()),
                'categories' => $this->options(OrganizationCategory::cases()),
                'jenjangs' => $this->options(Jenjang::cases()),
            ],
        ]);
    }

    public function store(Request $request, CreateOrganization $createOrganization): RedirectResponse
    {
        $request->validate([
            'parent_id' => ['nullable', 'uuid'],
            'type' => ['required', Rule::enum(OrganizationType::class)],
            'code' => ['required', 'string', self::CODE_RULE],
            'name' => ['required', 'string', 'max:200'],
            'category' => ['nullable', 'required_if:type,'.OrganizationType::Lembaga->value, 'prohibited_unless:type,'.OrganizationType::Lembaga->value, Rule::enum(OrganizationCategory::class)],
            'jenjang' => ['nullable', 'required_if:type,'.OrganizationType::Lembaga->value, 'prohibited_unless:type,'.OrganizationType::Lembaga->value, Rule::enum(Jenjang::class)],
        ], [
            'code.regex' => 'Kode hanya boleh berisi huruf, angka, dan tanda "-", panjang 2–50 karakter.',
            'category.required_if' => 'Lembaga wajib memiliki kategori.',
            'jenjang.required_if' => 'Lembaga wajib memiliki jenjang.',
            'category.prohibited_unless' => 'Kategori hanya untuk node berjenis lembaga.',
            'jenjang.prohibited_unless' => 'Jenjang hanya untuk node berjenis lembaga.',
        ], $this->attributes());

        $parentId = $request->string('parent_id')->value() ?: null;

        if ($parentId === null) {
            $this->authorizeRoot();
        } else {
            Gate::authorize(CoreAccess::ORGANIZATIONS_MANAGE, $this->find($parentId));
        }

        /** @var OrganizationType $type */
        $type = $request->enum('type', OrganizationType::class);

        $organization = $this->attempt(fn (): Organization => $createOrganization->handle(new NewOrganizationData(
            tenantId: $this->tenantId(),
            parentId: $parentId,
            type: $type,
            code: $request->string('code')->value(),
            name: $request->string('name')->value(),
            category: $request->enum('category', OrganizationCategory::class),
            jenjang: $request->enum('jenjang', Jenjang::class),
        )));

        return $this->done("{$organization->name} berhasil ditambahkan.");
    }

    public function update(Request $request, string $organization, RenameOrganization $renameOrganization): RedirectResponse
    {
        $node = $this->find($organization);
        Gate::authorize(CoreAccess::ORGANIZATIONS_MANAGE, $node);

        $request->validate(['name' => ['required', 'string', 'max:200']], [], $this->attributes());

        $renamed = $this->attempt(fn (): Organization => $renameOrganization->handle($this->tenantId(), $node->id, $request->string('name')->value()));

        return $this->done("Nama diubah menjadi {$renamed->name}.");
    }

    public function move(Request $request, string $organization, MoveOrganization $moveOrganization): RedirectResponse
    {
        $node = $this->find($organization);
        Gate::authorize(CoreAccess::ORGANIZATIONS_MANAGE, $node);

        $request->validate(['parent_id' => ['nullable', 'uuid']], [], $this->attributes());

        $parentId = $request->string('parent_id')->value() ?: null;

        // Tujuan juga wajib di dalam izin kelola, supaya node tidak bisa "dibuang" ke luar cakupan.
        if ($parentId === null) {
            $this->authorizeRoot();
        } else {
            Gate::authorize(CoreAccess::ORGANIZATIONS_MANAGE, $this->find($parentId));
        }

        $this->attempt(fn (): Organization => $moveOrganization->handle($this->tenantId(), $node->id, $parentId));

        return $this->done("{$node->name} berhasil dipindahkan.");
    }

    public function deactivate(string $organization, DeactivateOrganization $deactivateOrganization): RedirectResponse
    {
        $node = $this->find($organization);
        Gate::authorize(CoreAccess::ORGANIZATIONS_MANAGE, $node);

        // Mencegah pengguna mengunci dirinya sendiri: lembaga kerja yang sedang dipakai
        // tidak bisa dinonaktifkan dari dalam lembaga kerja itu.
        if ($this->tenantContext->require()->organizationId === $node->id) {
            throw ValidationException::withMessages([
                'form' => 'Lembaga kerja yang sedang Anda pakai tidak bisa dinonaktifkan dari sini. Minta pengelola di tingkat atasnya.',
            ]);
        }

        $this->attempt(fn (): Organization => $deactivateOrganization->handle($this->tenantId(), $node->id));

        return $this->done("{$node->name} dinonaktifkan.");
    }

    public function activate(string $organization, ReactivateOrganization $reactivateOrganization): RedirectResponse
    {
        $node = $this->find($organization);
        Gate::authorize(CoreAccess::ORGANIZATIONS_MANAGE, $node);

        $this->attempt(fn (): Organization => $reactivateOrganization->handle($this->tenantId(), $node->id));

        return $this->done("{$node->name} diaktifkan kembali.");
    }

    // ── Bantuan ───────────────────────────────────────────────────────────────

    /**
     * Cari node di yayasan aktif (filter yayasan otomatis). Tidak ada → 404.
     */
    private function find(string $id): Organization
    {
        $organization = Str::isUuid($id) ? Organization::query()->find($id) : null;

        if (! $organization instanceof Organization) {
            abort(404);
        }

        return $organization;
    }

    private function canManageRoot(): bool
    {
        return $this->authorization->inspectTarget(CoreAccess::ORGANIZATIONS_MANAGE, null, null)->allowed();
    }

    private function authorizeRoot(): void
    {
        $this->authorization->inspectTarget(CoreAccess::ORGANIZATIONS_MANAGE, null, null)->authorize();
    }

    private function tenantId(): string
    {
        return $this->tenantContext->require()->tenantId;
    }

    /**
     * Jalankan service; pelanggaran aturan pohon ditampilkan sebagai pesan form.
     *
     * @param  callable(): Organization  $action
     */
    private function attempt(callable $action): Organization
    {
        try {
            return $action();
        } catch (OrganizationTreeException $exception) {
            throw ValidationException::withMessages(['form' => $exception->getMessage()]);
        }
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }

    /**
     * @param  list<OrganizationType|OrganizationCategory|Jenjang>  $cases
     * @return list<array{value: string, label: string}>
     */
    private function options(array $cases): array
    {
        return array_map(fn (OrganizationType|OrganizationCategory|Jenjang $case): array => [
            'value' => $case->value,
            'label' => $case->label(),
        ], $cases);
    }

    /**
     * @return array<string, string>
     */
    private function attributes(): array
    {
        return [
            'parent_id' => 'induk',
            'type' => 'jenis',
            'code' => 'kode',
            'name' => 'nama',
            'category' => 'kategori',
            'jenjang' => 'jenjang',
        ];
    }
}
