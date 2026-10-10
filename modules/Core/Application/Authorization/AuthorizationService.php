<?php

namespace Modules\Core\Application\Authorization;

use Illuminate\Auth\Access\Response;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Modules\Core\Contracts\OwnedByOrganization;
use Modules\Core\Domain\Identity\User;
use Modules\Core\Domain\Organization\AssignmentStatus;
use Modules\Core\Domain\Organization\Jenjang;
use Modules\Core\Domain\Organization\OrganizationStatus;
use Modules\Core\Domain\Tenancy\TenantContext;
use Modules\Core\Domain\Tenancy\WorkContext;

/**
 * Otorisasi berbasis pohon untuk pengguna yang sedang bekerja (PRD-000 §7).
 *
 *   Permission efektif di node N = role tenant-wide ∪ role dari setiap penugasan yang cakupannya memuat N
 *   Data yang boleh dilihat     = node tsb ∩ cakupan lembaga kerja aktif
 *
 * Jawaban atas akses ke sebuah node (OD-13):
 *   - di luar cakupan pengguna (atau yayasan lain) → 404, agar keberadaan data tidak bocor
 *   - di dalam cakupan tetapi tanpa permission aksi itu → 403
 *
 * Semua dihitung dari database pada setiap request; tidak ada cache lintas request.
 * Superadmin TIDAK otomatis lolos (OD-12): ia butuh membership & role seperti orang lain.
 */
final class AuthorizationService
{
    /** Konteks kerja yang menjadi dasar cache di bawah ini. */
    private ?WorkContext $cachedFor = null;

    private ?string $membershipPersonId = null;

    /** @var array<string, true>|null */
    private ?array $tenantPermissions = null;

    /** @var list<AccessGrant>|null */
    private ?array $assignmentGrants = null;

    /** @var array<string, array<string, true>> */
    private array $visibleCache = [];

    /** @var array<string, true>|null */
    private ?array $reachableCache = null;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AccessScope $scope,
    ) {}

    // ── API untuk modul ───────────────────────────────────────────────────────

    /**
     * Boleh melakukan $permission? Tanpa $organizationId: "di setidaknya satu node lembaga kerja".
     */
    public function allows(string $permission, ?string $organizationId = null): bool
    {
        $context = $this->context();

        if ($context === null) {
            return false;
        }

        if ($organizationId === null) {
            return $this->allowsAnywhere($permission);
        }

        return isset($this->visibleSet($permission)[$organizationId]);
    }

    /**
     * ID node yang datanya boleh dibuka dengan $permission (PRD-000 §7.2). Pakai untuk menyaring query:
     *
     *   ->whereIn('organization_id', $authorization->visibleNodeIds('hr.employees.view'))
     *
     * @return list<string>
     */
    public function visibleNodeIds(string $permission): array
    {
        return $this->context() === null ? [] : array_keys($this->visibleSet($permission));
    }

    /**
     * Daftar permission yang dimiliki di lembaga kerja aktif. Hanya petunjuk untuk menu di UI;
     * setiap aksi tetap dicek ulang di server (PRD-000 §7.1).
     *
     * @return list<string>
     */
    public function permissions(): array
    {
        if ($this->context() === null) {
            return [];
        }

        $keys = array_keys($this->tenantPermissions());

        foreach ($this->assignmentGrants() as $grant) {
            foreach (array_keys($grant->permissions) as $permission) {
                if ($this->allowsAnywhere($permission)) {
                    $keys[] = $permission;
                }
            }
        }

        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys;
    }

    /**
     * Cek akses ke sebuah node atau data milik node, untuk Gate (lihat CoreServiceProvider).
     */
    public function inspect(User $user, string $permission, mixed $subject = null): Response
    {
        $context = $this->context();

        if ($context === null || ! $this->isCurrentMember($user)) {
            return $this->deny();
        }

        // Tanpa data, atau nama class (Gate::authorize('hr.employees.create', Employee::class)):
        // cukup punya permission di setidaknya satu node lembaga kerja. Aksi terhadap data
        // tertentu WAJIB mengirim datanya agar node pemiliknya ikut dicek.
        if ($subject === null || is_string($subject)) {
            return $this->allowsAnywhere($permission) ? Response::allow() : $this->deny();
        }

        if (! $subject instanceof OwnedByOrganization) {
            return $this->deny();
        }

        $nodeId = $subject->owningOrganizationId();

        if ($subject->owningTenantId() !== $context->tenantId || ! isset($this->reachableSet()[$nodeId])) {
            return Response::denyAsNotFound();
        }

        return isset($this->visibleSet($permission)[$nodeId]) ? Response::allow() : $this->deny();
    }

    /**
     * Cek akses ke sebuah "target" (node X, jenjang J), mis. tempat menyimpan aturan berjenjang (PRD-000 §8.3).
     *
     *   X kosong, J kosong → tingkat Yayasan, untuk semua
     *   X kosong, J terisi → tingkat Yayasan, khusus lembaga berjenjang J
     *   X terisi, J kosong → node X dan turunannya
     *   X terisi, J terisi → lembaga berjenjang J di bawah X
     */
    public function inspectTarget(string $permission, ?string $organizationId, ?Jenjang $jenjang): Response
    {
        $context = $this->context();

        if ($context === null) {
            return $this->deny();
        }

        if ($organizationId !== null && ! isset($this->scope->allNodes($context->tenantId)[$organizationId])) {
            return Response::denyAsNotFound();
        }

        if ($this->allowsTarget($context, $permission, $organizationId, $jenjang)) {
            return Response::allow();
        }

        if ($organizationId !== null && ! isset($this->reachableSet()[$organizationId])) {
            return Response::denyAsNotFound();
        }

        return $this->deny();
    }

    // ── Perhitungan ───────────────────────────────────────────────────────────

    private function allowsAnywhere(string $permission): bool
    {
        return isset($this->tenantPermissions()[$permission]) || $this->visibleSet($permission) !== [];
    }

    /**
     * @return array<string, true>
     */
    private function visibleSet(string $permission): array
    {
        $context = $this->context();

        if ($context === null) {
            return [];
        }

        if (isset($this->visibleCache[$permission])) {
            return $this->visibleCache[$permission];
        }

        $workspace = $this->workspaceSet($context);

        if (isset($this->tenantPermissions()[$permission])) {
            return $this->visibleCache[$permission] = $workspace;
        }

        $visible = [];

        foreach ($this->assignmentGrants() as $grant) {
            if ($grant->grants($permission)) {
                $visible += $this->scope->coverage($context->tenantId, $grant->organizationId, $grant->jenjangFilter);
            }
        }

        return $this->visibleCache[$permission] = array_intersect_key($visible, $workspace);
    }

    /**
     * Node yang tercakup oleh hak akses apa pun milik pengguna, di dalam lembaga kerja aktif.
     * Di luar set ini, jawaban akses selalu 404.
     *
     * @return array<string, true>
     */
    private function reachableSet(): array
    {
        $context = $this->context();

        if ($context === null) {
            return [];
        }

        if ($this->reachableCache !== null) {
            return $this->reachableCache;
        }

        $workspace = $this->workspaceSet($context);

        if ($this->tenantPermissions() !== []) {
            return $this->reachableCache = $workspace;
        }

        $reachable = [];

        foreach ($this->assignmentGrants() as $grant) {
            $reachable += $this->scope->coverage($context->tenantId, $grant->organizationId, $grant->jenjangFilter);
        }

        return $this->reachableCache = array_intersect_key($reachable, $workspace);
    }

    /**
     * Cakupan lembaga kerja aktif: seluruh yayasan, atau cakupan penugasan yang dipilih.
     *
     * @return array<string, true>
     */
    private function workspaceSet(WorkContext $context): array
    {
        if ($context->assignmentId === null) {
            return $this->scope->allNodes($context->tenantId);
        }

        return $this->scope->coverage($context->tenantId, $context->organizationId, $context->jenjangFilter);
    }

    private function allowsTarget(WorkContext $context, string $permission, ?string $organizationId, ?Jenjang $jenjang): bool
    {
        $workspaceAllows = $context->assignmentId === null
            || $this->scopeContainsTarget($context->tenantId, $context->organizationId, $context->jenjangFilter, $organizationId, $jenjang);

        if (! $workspaceAllows) {
            return false;
        }

        if (isset($this->tenantPermissions()[$permission])) {
            return true;
        }

        foreach ($this->assignmentGrants() as $grant) {
            if ($grant->grants($permission)
                && $this->scopeContainsTarget($context->tenantId, $grant->organizationId, $grant->jenjangFilter, $organizationId, $jenjang)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Apakah cakupan (A, F) memuat target (X, J)? (PRD-000 §8.3)
     *
     * - X berada di dalam cakupan (A, F) → ya, apa pun J-nya.
     * - Cakupan fungsional F dan target khusus jenjang F (J = F) → ya bila X kosong dan A kosong
     *   (tingkat Yayasan), atau X berada di bawah A. Contoh: Koordinator MDA boleh menetapkan
     *   aturan "untuk semua MDA" di tingkat Yayasan atau di Unit 2, tapi tidak aturan umum Unit 2.
     */
    private function scopeContainsTarget(string $tenantId, ?string $scopeNodeId, ?Jenjang $scopeJenjang, ?string $targetNodeId, ?Jenjang $targetJenjang): bool
    {
        if ($scopeNodeId === null && $scopeJenjang === null) {
            return true;
        }

        if ($targetNodeId !== null && isset($this->scope->coverage($tenantId, $scopeNodeId, $scopeJenjang)[$targetNodeId])) {
            return true;
        }

        if ($scopeJenjang === null || $targetJenjang !== $scopeJenjang) {
            return false;
        }

        if ($scopeNodeId === null) {
            return true;
        }

        return $targetNodeId !== null && isset($this->scope->coverage($tenantId, $scopeNodeId, null)[$targetNodeId]);
    }

    // ── Data dari database ────────────────────────────────────────────────────

    /**
     * @return array<string, true>
     */
    private function tenantPermissions(): array
    {
        $context = $this->context();

        if ($context === null) {
            return [];
        }

        if ($this->tenantPermissions !== null) {
            return $this->tenantPermissions;
        }

        $keys = DB::table('membership_roles as mr')
            ->join('role_permission as rp', 'rp.role_id', '=', 'mr.role_id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('mr.tenant_id', $context->tenantId)
            ->where('mr.membership_id', $context->membershipId)
            ->distinct()
            ->pluck('p.key');

        $set = [];

        foreach ($keys as $key) {
            $set[(string) $key] = true;
        }

        return $this->tenantPermissions = $set;
    }

    /**
     * Penugasan aktif (di lembaga yang masih aktif) beserta permission dari role-nya.
     *
     * @return list<AccessGrant>
     */
    private function assignmentGrants(): array
    {
        $context = $this->context();

        if ($context === null) {
            return [];
        }

        if ($this->assignmentGrants !== null) {
            return $this->assignmentGrants;
        }

        $rows = DB::table('organizational_assignments as oa')
            ->join('organizational_assignment_roles as ar', function (JoinClause $join): void {
                $join->on('ar.tenant_id', '=', 'oa.tenant_id')
                    ->on('ar.organizational_assignment_id', '=', 'oa.id');
            })
            ->join('role_permission as rp', 'rp.role_id', '=', 'ar.role_id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->leftJoin('organizations as o', function (JoinClause $join): void {
                $join->on('o.tenant_id', '=', 'oa.tenant_id')
                    ->on('o.id', '=', 'oa.organization_id');
            })
            ->where('oa.tenant_id', $context->tenantId)
            ->where('oa.membership_id', $context->membershipId)
            ->where('oa.status', AssignmentStatus::Active->value)
            ->where(function ($query): void {
                $query->whereNull('oa.organization_id')
                    ->orWhere('o.status', OrganizationStatus::Active->value);
            })
            ->select(['oa.id', 'oa.organization_id', 'oa.jenjang_filter', 'p.key'])
            ->get();

        /** @var array<string, array{organization_id: string|null, jenjang: string|null, permissions: array<string, true>}> $byAssignment */
        $byAssignment = [];

        foreach ($rows as $row) {
            $assignmentId = (string) $row->id;

            $byAssignment[$assignmentId] ??= [
                'organization_id' => $row->organization_id === null ? null : (string) $row->organization_id,
                'jenjang' => $row->jenjang_filter === null ? null : (string) $row->jenjang_filter,
                'permissions' => [],
            ];
            $byAssignment[$assignmentId]['permissions'][(string) $row->key] = true;
        }

        $grants = [];

        foreach ($byAssignment as $assignment) {
            $grants[] = new AccessGrant(
                organizationId: $assignment['organization_id'],
                jenjangFilter: $assignment['jenjang'] === null ? null : Jenjang::from($assignment['jenjang']),
                permissions: $assignment['permissions'],
            );
        }

        return $this->assignmentGrants = $grants;
    }

    /**
     * Gate bisa saja dipanggil untuk user lain (Gate::forUser). Konteks kerja hanya
     * milik pengguna request ini, jadi user lain selalu ditolak.
     */
    private function isCurrentMember(User $user): bool
    {
        $context = $this->context();

        if ($context === null) {
            return false;
        }

        $this->membershipPersonId ??= (string) DB::table('memberships')
            ->where('id', $context->membershipId)
            ->value('person_id');

        return $this->membershipPersonId === $user->person_id;
    }

    /**
     * Konteks kerja aktif. Bila konteks berganti (request baru), semua cache dikosongkan.
     */
    private function context(): ?WorkContext
    {
        $context = $this->tenantContext->get();

        if ($context !== $this->cachedFor) {
            $this->cachedFor = $context;
            $this->forgetCachedAccess();
        }

        return $context;
    }

    /**
     * Kosongkan hasil hitungan, mis. di awal request atau setelah role/penugasan diubah
     * di tengah proses yang sama.
     */
    public function flush(): void
    {
        $this->cachedFor = $this->tenantContext->get();
        $this->forgetCachedAccess();
    }

    private function forgetCachedAccess(): void
    {
        $this->membershipPersonId = null;
        $this->tenantPermissions = null;
        $this->assignmentGrants = null;
        $this->visibleCache = [];
        $this->reachableCache = null;
        $this->scope->flush();
    }

    private function deny(): Response
    {
        return Response::deny('Anda tidak memiliki izin untuk tindakan ini.');
    }
}
