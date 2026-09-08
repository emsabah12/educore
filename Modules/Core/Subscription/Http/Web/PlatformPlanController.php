<?php

declare(strict_types=1);

namespace Modules\Core\Subscription\Http\Web;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Core\Subscription\Models\SubscriptionFeature;
use Modules\Core\Subscription\Models\SubscriptionPlan;

/**
 * Katalog paket subscription — GLOBAL, superadmin saja (lihat
 * `PlatformRoleController` untuk alasan pemisahan serupa: katalog
 * global vs data tenant tidak boleh dicampur otoritas edit-nya).
 *
 * `code` TIDAK BISA diubah setelah dibuat — machine-readable identity
 * yang berpotensi dijadikan rujukan tetap di kode lain nanti (pola
 * yang sama seperti `name` pada role sistem).
 */
final class PlatformPlanController extends Controller
{
    public function index(): View
    {
        $plans = SubscriptionPlan::query()
            ->withCount('features')
            ->orderBy('name')
            ->get();

        return view('platform.plans.index', [
            'plans' => $plans,
        ]);
    }

    public function create(): View
    {
        return view('platform.plans.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'max:50',
                'regex:/^[a-z0-9](?:[a-z0-9-]{0,48}[a-z0-9])?$/',
                Rule::unique('subscription_plans', 'code'),
            ],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'grace_period_days' => ['required', 'integer', 'min:1', 'max:365'],
        ], [
            'code.regex' => 'Kode paket hanya boleh huruf kecil, angka, dan tanda hubung.',
        ]);

        $plan = SubscriptionPlan::query()->create($validated);

        return redirect()
            ->route('platform.plans.show', $plan->id)
            ->with('status', sprintf('Paket "%s" berhasil dibuat.', $plan->name));
    }

    public function show(string $planId): View
    {
        $plan = SubscriptionPlan::query()
            ->with('features')
            ->findOrFail($planId);

        $assignedFeatureIds = $plan->features->pluck('id')->all();
        $allFeatures = SubscriptionFeature::query()->orderBy('name')->get();

        return view('platform.plans.show', [
            'plan' => $plan,
            'allFeatures' => $allFeatures,
            'assignedFeatureIds' => $assignedFeatureIds,
        ]);
    }

    /**
     * Memperbarui atribut paket (nama, deskripsi, `grace_period_days`)
     * DAN menyamakan (sync) fitur bawaannya sekaligus — satu form,
     * satu submit, karena keduanya sama-sama "mendefinisikan paket"
     * seperti yang diminta ("nanti bisa diatur oleh superadmin saat
     * mendefinisikan paket subscription"). `code` SENGAJA tidak ada
     * di rules maupun payload yang diproses.
     */
    public function update(Request $request, string $planId): RedirectResponse
    {
        $plan = SubscriptionPlan::query()->findOrFail($planId);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'grace_period_days' => ['required', 'integer', 'min:1', 'max:365'],
            'is_active' => ['sometimes', 'boolean'],
            'feature_ids' => ['sometimes', 'array'],
            'feature_ids.*' => ['uuid', Rule::exists('subscription_features', 'id')],
        ]);

        $plan->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'grace_period_days' => $validated['grace_period_days'],
            'is_active' => $validated['is_active'] ?? false,
        ]);

        $plan->features()->sync($validated['feature_ids'] ?? []);

        return redirect()
            ->route('platform.plans.show', $plan->id)
            ->with('status', sprintf('Paket "%s" berhasil diperbarui.', $plan->name));
    }
}
