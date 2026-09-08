<?php

declare(strict_types=1);

namespace Modules\Core\Subscription\Http\Web;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Core\Subscription\Models\Addon;
use Modules\Core\Subscription\Models\SubscriptionFeature;

/**
 * Katalog add-on — GLOBAL, superadmin saja. `code` TIDAK BISA diubah
 * setelah dibuat, sama seperti `SubscriptionPlan`.
 */
final class PlatformAddonController extends Controller
{
    public function index(): View
    {
        $addons = Addon::query()
            ->with('feature')
            ->orderBy('name')
            ->get();

        return view('platform.addons.index', [
            'addons' => $addons,
        ]);
    }

    public function create(): View
    {
        $features = SubscriptionFeature::query()->orderBy('name')->get();

        return view('platform.addons.create', [
            'features' => $features,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'max:100',
                'regex:/^[a-z0-9](?:[a-z0-9-]{0,98}[a-z0-9])?$/',
                Rule::unique('addons', 'code'),
            ],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:255'],
            'feature_id' => ['required', 'uuid', Rule::exists('subscription_features', 'id')],
        ], [
            'code.regex' => 'Kode add-on hanya boleh huruf kecil, angka, dan tanda hubung.',
        ]);

        $addon = Addon::query()->create($validated);

        return redirect()
            ->route('platform.addons.show', $addon->id)
            ->with('status', sprintf('Add-on "%s" berhasil dibuat.', $addon->name));
    }

    public function show(string $addonId): View
    {
        $addon = Addon::query()->with('feature')->findOrFail($addonId);
        $features = SubscriptionFeature::query()->orderBy('name')->get();

        return view('platform.addons.show', [
            'addon' => $addon,
            'features' => $features,
        ]);
    }

    public function update(Request $request, string $addonId): RedirectResponse
    {
        $addon = Addon::query()->findOrFail($addonId);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:255'],
            'feature_id' => ['required', 'uuid', Rule::exists('subscription_features', 'id')],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $addon->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'feature_id' => $validated['feature_id'],
            'is_active' => $validated['is_active'] ?? false,
        ]);

        return redirect()
            ->route('platform.addons.show', $addon->id)
            ->with('status', sprintf('Add-on "%s" berhasil diperbarui.', $addon->name));
    }
}
