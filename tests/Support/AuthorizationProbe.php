<?php

namespace Tests\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Core\Application\Authorization\AuthorizationService;
use Modules\Core\Application\Authorization\CoreAccess;
use Modules\Core\Application\Settings\ScopedSettingResolver;
use Modules\Core\Application\Settings\SetScopedSetting;
use Modules\Core\Domain\Organization\Jenjang;
use Modules\Core\Domain\Organization\Organization;
use Modules\Core\Domain\Settings\Exceptions\SettingException;
use Modules\Core\Domain\Tenancy\TenantContext;

/**
 * Route khusus test yang meniru cara modul bisnis memakai otorisasi Core.
 *
 * Belum ada data bisnis (pegawai, siswa) di F3, jadi node lembaga itu sendiri dipakai
 * sebagai "data": membuka lembaga X = membuka data milik X. Semua route melewati
 * middleware yang sama dengan halaman asli (`auth` + `work.context`).
 */
final class AuthorizationProbe
{
    /** Aturan contoh yang hanya didefinisikan di test. */
    public const SETTING_KEY = 'uji.jam_masuk';

    public static function register(): void
    {
        Route::middleware(['web', 'auth', 'work.context'])->prefix('_uji')->group(function (): void {
            // Buka satu data milik lembaga: 200, 403, atau 404. ?izin= untuk permission lain.
            Route::get('lembaga/{id}', function (Request $request, string $id): JsonResponse {
                $query = Organization::query();

                // ?lintas=1: lewati filter yayasan otomatis, untuk menguji bahwa Gate sendiri juga menolak.
                if ($request->boolean('lintas')) {
                    $query->withoutGlobalScope('tenant');
                }

                $organization = Str::isUuid($id) ? $query->find($id) : null;

                if (! $organization instanceof Organization) {
                    abort(404);
                }

                Gate::authorize($request->string('izin', CoreAccess::ORGANIZATIONS_VIEW)->value(), $organization);

                return response()->json(['code' => $organization->code]);
            });

            // Gate dengan nama class, mis. tombol "Tambah" yang belum menyangkut data tertentu.
            Route::get('izin-umum/{permission}', function (string $permission): JsonResponse {
                return response()->json(['allowed' => Gate::allows($permission, Organization::class)]);
            });

            // Daftar data yang terlihat untuk sebuah permission (penyaringan query, §7.2).
            Route::get('terlihat/{permission}', function (string $permission, AuthorizationService $authorization): JsonResponse {
                $codes = Organization::query()
                    ->whereIn('id', $authorization->visibleNodeIds($permission))
                    ->orderBy('code')
                    ->pluck('code')
                    ->all();

                return response()->json($codes);
            });

            // Tetapkan aturan berjenjang lewat service resmi.
            Route::post('aturan', function (Request $request, SetScopedSetting $setSetting, TenantContext $tenantContext): JsonResponse {
                $organizationId = null;

                if ($request->filled('node')) {
                    $organizationId = Organization::query()
                        ->withoutGlobalScope('tenant')
                        ->where('code', $request->string('node')->value())
                        ->value('id');
                }

                try {
                    $setting = $setSetting->handle(
                        $request->string('key')->value(),
                        is_string($organizationId) ? $organizationId : null,
                        $request->filled('jenjang') ? Jenjang::from($request->string('jenjang')->value()) : null,
                        $request->input('value'),
                        $request->boolean('enforced'),
                    );
                } catch (SettingException $exception) {
                    return response()->json(['message' => $exception->getMessage()], 422);
                }

                return response()->json(['id' => $setting->id, 'tenant_id' => $tenantContext->require()->tenantId]);
            });

            // Nilai aturan yang berlaku di sebuah node (?node=KODE), atau tingkat Yayasan bila tanpa node.
            Route::get('aturan/{key}', function (Request $request, string $key, ScopedSettingResolver $resolver): JsonResponse {
                $organizationId = $request->filled('node')
                    ? Organization::query()->where('code', $request->string('node')->value())->value('id')
                    : null;
                $resolved = $resolver->current($key, is_string($organizationId) ? $organizationId : null);

                return response()->json(['value' => $resolved->value, 'source' => $resolved->source]);
            });
        });
    }
}
