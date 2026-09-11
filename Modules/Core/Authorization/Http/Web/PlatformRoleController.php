<?php

declare(strict_types=1);

namespace Modules\Core\Authorization\Http\Web;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Core\Authorization\Models\Permission;
use Modules\Core\Authorization\Models\Role;

/**
 * Katalog role & permission PLATFORM — GLOBAL, dipakai lintas semua
 * tenant (tabel `roles`/`permissions`/`role_permissions` TIDAK punya
 * `tenant_id`; assignment ke membership tertentu ada di tabel terpisah
 * `membership_roles`). Ini BUKAN pengelolaan role di dalam satu tenant.
 *
 * §Batasan yang sengaja diterapkan:
 * - TIDAK ADA fitur hapus role: `role_permissions` dan
 *   `membership_roles` sama-sama `cascadeOnDelete()`, jadi menghapus
 *   satu role bisa mencabut akses ratusan membership lintas tenant
 *   tanpa peringatan. Itu operasi berdampak luas yang butuh alur
 *   tersendiri (mis. tampilkan dulu jumlah membership terdampak),
 *   bukan tombol biasa.
 * - TIDAK ADA fitur ubah nama role: nama role `admin` di-hardcode di
 *   `TenantProvisioningService`/`AuthorizationCatalogSeeder`; nama
 *   role lain mungkin juga diasumsikan tetap oleh seeder modul
 *   masing-masing. Hanya deskripsi & kepemilikan permission yang
 *   boleh diubah lewat sini.
 * - HANYA role GLOBAL (`tenant_id IS NULL`) — sejak Step D, `roles`
 *   juga menyimpan role KUSTOM milik satu tenant tertentu
 *   (`roles.tenant_id` terisi). Halaman superadmin ini SENGAJA
 *   menyaring `whereNull('tenant_id')` di setiap query supaya role
 *   kustom milik tenant TIDAK PERNAH bisa dilihat/diedit dari sini —
 *   itu domain admin tenant sendiri (lihat `TenantRoleService`).
 */
final class PlatformRoleController extends Controller
{
    public function index(): View
    {
        $roles = Role::query()
            ->whereNull('tenant_id')
            ->withCount('permissions')
            ->orderBy('name')
            ->get();

        return view('platform.roles.index', [
            'roles' => $roles,
        ]);
    }

    public function show(string $roleId): View
    {
        $role = Role::query()
            ->whereNull('tenant_id')
            ->with('permissions')
            ->findOrFail($roleId);

        $assignedPermissionIds = $role->permissions
            ->pluck('id')
            ->all();

        $permissionsByModule = Permission::query()
            ->orderBy('module')
            ->orderBy('name')
            ->get()
            ->groupBy('module');

        return view('platform.roles.show', [
            'role' => $role,
            'permissionsByModule' => $permissionsByModule,
            'assignedPermissionIds' => $assignedPermissionIds,
        ]);
    }

    /**
     * Menyamakan (sync) permission suatu role dengan daftar yang
     * dicentang di form — TIDAK menerima perubahan `name`, sengaja
     * tidak ada di rules maupun di payload yang diproses.
     */
    public function update(Request $request, string $roleId): RedirectResponse
    {
        $role = Role::query()
            ->whereNull('tenant_id')
            ->findOrFail($roleId);

        $validated = $request->validate([
            'permission_ids' => ['sometimes', 'array'],
            'permission_ids.*' => ['uuid', Rule::exists('permissions', 'id')],
        ]);

        $role->permissions()->sync($validated['permission_ids'] ?? []);

        return redirect()
            ->route('platform.roles.show', $role->id)
            ->with('status', sprintf(
                'Permission untuk role "%s" berhasil diperbarui.',
                $role->display_name,
            ));
    }

    public function create(): View
    {
        return view('platform.roles.create');
    }

    /**
     * `Rule::unique` DISENGAJAKAN discope `whereNull('tenant_id')` —
     * sejak Step D, constraint unik di database untuk `name` juga
     * cuma berlaku di antara role GLOBAL (lihat migrasi
     * `add_tenant_id_to_roles_table`). Tanpa scope ini, validasi bisa
     * salah menolak nama role global baru hanya karena kebetulan sama
     * dengan nama role KUSTOM milik satu tenant tertentu.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:150',
                'regex:/^[a-z0-9](?:[a-z0-9._-]{0,148}[a-z0-9])?$/',
                Rule::unique('roles', 'name')->whereNull('tenant_id'),
            ],
            'display_name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
        ], [
            'name.regex' => 'Nama role hanya boleh huruf kecil, angka, titik, dan tanda hubung.',
        ]);

        $role = DB::transaction(
            fn () => Role::query()->create($validated),
        );

        return redirect()
            ->route('platform.roles.show', $role->id)
            ->with('status', sprintf(
                'Role "%s" berhasil dibuat. Atur permission-nya di bawah.',
                $role->display_name,
            ));
    }
}
