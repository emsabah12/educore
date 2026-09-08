@extends('platform.layouts.app', ['title' => $tenant['name'] . ' — EduCore Platform'])

@section('content')
<div class="min-h-screen">
    @include('platform.partials.nav')

    <main class="p-6 max-w-3xl">
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-xl font-semibold">{{ $tenant['name'] }}</h1>
                <p class="text-sm text-slate-500">{{ $tenant['subdomain'] }}</p>
            </div>
            <a href="{{ route('platform.tenants.index') }}" class="text-sm text-slate-500 hover:text-slate-800">
                &larr; Kembali
            </a>
        </div>

        @if (session('status'))
        <div class="mb-4 rounded-md bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-700">
            {{ session('status') }}
        </div>
        @endif

        @if (session('error'))
        <div class="mb-4 rounded-md bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">
            {{ session('error') }}
        </div>
        @endif

        <div class="bg-white rounded-lg border border-slate-200 p-6 mb-6">
            <dl class="grid grid-cols-2 gap-4 text-sm">
                <div>
                    <dt class="text-slate-500">Status</dt>
                    <dd class="mt-1">
                        @if ($tenant['is_active'])
                        <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-1 text-xs font-medium text-emerald-700">
                            Aktif
                        </span>
                        @else
                        <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-1 text-xs font-medium text-slate-600">
                            Nonaktif
                        </span>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-slate-500">Dibuat</dt>
                    <dd class="mt-1 text-slate-900">
                        {{ \Illuminate\Support\Carbon::parse($tenant['created_at'])->format('d M Y H:i') }}
                    </dd>
                </div>
            </dl>

            <form method="POST" action="{{ route('platform.tenants.toggle-status', $tenant['id']) }}" class="mt-6">
                @csrf
                @if ($tenant['is_active'])
                <button
                    type="submit"
                    class="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-500"
                    onclick="return confirm('Nonaktifkan tenant ini? Pengguna di tenant ini tidak akan bisa mengakses sistem.');">
                    Nonaktifkan Tenant
                </button>
                @else
                <button
                    type="submit"
                    class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-500">
                    Aktifkan Tenant
                </button>
                @endif
            </form>
        </div>

        <div class="bg-white rounded-lg border border-slate-200 p-6 mb-6">
            <h2 class="text-sm font-semibold text-slate-700 mb-3">Paket Langganan</h2>

            @if ($subscription)
            <p class="text-sm text-slate-900">
                {{ $subscription->plan->name }}
                <span class="text-slate-500 font-mono text-xs">({{ $subscription->plan->code }})</span>
            </p>
            <p class="text-xs text-slate-500 mt-1">
                Status:
                @if ($subscription->status === 'trial')
                <span class="inline-flex items-center rounded-full bg-amber-50 px-2 py-1 text-xs font-medium text-amber-700">Trial</span>
                @else
                <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-1 text-xs font-medium text-emerald-700">Aktif</span>
                @endif
            </p>

            @if ($subscription->status === 'trial')
            <form method="POST" action="{{ route('platform.tenants.subscription.activate-plan', $tenant['id']) }}" class="mt-3">
                @csrf
                <button type="submit" class="rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-emerald-500">
                    Aktifkan Paket (Setelah Pembayaran/Approval)
                </button>
            </form>
            @endif
            @else
            <p class="text-sm text-slate-500">Tenant ini belum memiliki paket.</p>
            @endif

            <form method="POST" action="{{ route('platform.tenants.subscription.assign-plan', $tenant['id']) }}" class="mt-4 flex items-end gap-2">
                @csrf
                <div class="flex-1">
                    <label for="plan_id" class="block text-xs font-medium text-slate-500 mb-1">Ganti Paket</label>
                    <select name="plan_id" id="plan_id" required class="block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">— Pilih paket —</option>
                        @foreach ($availablePlans as $plan)
                        <option value="{{ $plan->id }}">{{ $plan->name }} ({{ $plan->code }})</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="rounded-md bg-slate-800 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">
                    Ganti
                </button>
            </form>
        </div>

        <div class="bg-white rounded-lg border border-slate-200 p-6 mb-6">
            <h2 class="text-sm font-semibold text-slate-700 mb-3">Add-on</h2>

            <div class="divide-y divide-slate-100 mb-4">
                @forelse ($tenantAddons as $tenantAddon)
                <div class="py-3 flex items-center justify-between">
                    <div>
                        <p class="text-sm text-slate-900">{{ $tenantAddon->addon->name }}</p>
                        <p class="text-xs text-slate-500 font-mono">{{ $tenantAddon->addon->feature->code }}</p>
                    </div>
                    <div class="flex items-center gap-2">
                        @if ($tenantAddon->status === 'trial')
                        <span class="inline-flex items-center rounded-full bg-amber-50 px-2 py-1 text-xs font-medium text-amber-700">Trial</span>
                        <form method="POST" action="{{ route('platform.tenants.addons.activate', [$tenant['id'], $tenantAddon->addon_id]) }}">
                            @csrf
                            <button type="submit" class="text-xs text-emerald-600 hover:underline">Aktifkan</button>
                        </form>
                        <form method="POST" action="{{ route('platform.tenants.addons.revoke', [$tenant['id'], $tenantAddon->addon_id]) }}">
                            @csrf
                            <button type="submit" class="text-xs text-red-600 hover:underline">Cabut</button>
                        </form>
                        @elseif ($tenantAddon->status === 'active')
                        <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-1 text-xs font-medium text-emerald-700">Aktif</span>
                        <form method="POST" action="{{ route('platform.tenants.addons.revoke', [$tenant['id'], $tenantAddon->addon_id]) }}">
                            @csrf
                            <button type="submit" class="text-xs text-red-600 hover:underline">Cabut</button>
                        </form>
                        @elseif ($tenantAddon->status === 'locked_readonly')
                        <span class="inline-flex items-center rounded-full bg-orange-50 px-2 py-1 text-xs font-medium text-orange-700">
                            Read-only sampai {{ $tenantAddon->readonly_until?->format('d M Y') }}
                        </span>
                        <form method="POST" action="{{ route('platform.tenants.addons.assign', $tenant['id']) }}">
                            @csrf
                            <input type="hidden" name="addon_id" value="{{ $tenantAddon->addon_id }}">
                            <button type="submit" class="text-xs text-indigo-600 hover:underline">Aktifkan Kembali</button>
                        </form>
                        @else
                        <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-1 text-xs font-medium text-slate-600">Disembunyikan</span>
                        <form method="POST" action="{{ route('platform.tenants.addons.assign', $tenant['id']) }}">
                            @csrf
                            <input type="hidden" name="addon_id" value="{{ $tenantAddon->addon_id }}">
                            <button type="submit" class="text-xs text-indigo-600 hover:underline">Aktifkan Kembali</button>
                        </form>
                        @endif
                    </div>
                </div>
                @empty
                <p class="text-sm text-slate-500 py-3">Belum ada add-on untuk tenant ini.</p>
                @endforelse
            </div>

            @if ($availableAddonsToAdd->isNotEmpty())
            <form method="POST" action="{{ route('platform.tenants.addons.assign', $tenant['id']) }}" class="flex items-end gap-2 pt-3 border-t border-slate-100">
                @csrf
                <div class="flex-1">
                    <label for="addon_id" class="block text-xs font-medium text-slate-500 mb-1">Tambah Add-on</label>
                    <select name="addon_id" id="addon_id" required class="block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">— Pilih add-on —</option>
                        @foreach ($availableAddonsToAdd as $addon)
                        <option value="{{ $addon->id }}">{{ $addon->name }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="rounded-md bg-slate-800 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">
                    Tambah
                </button>
            </form>
            @endif
        </div>

        <h2 class="text-lg font-semibold mb-3">Riwayat Aktivitas</h2>
        <div class="bg-white rounded-lg border border-slate-200 divide-y divide-slate-100">
            @forelse ($auditLogs as $log)
            <div class="px-4 py-3">
                <p class="text-sm text-slate-900">{{ $log->description }}</p>
                <p class="text-xs text-slate-500 mt-1">
                    {{ \Illuminate\Support\Carbon::parse($log->created_at)->format('d M Y H:i') }}
                    &middot; {{ $log->event_type }}
                </p>
            </div>
            @empty
            <div class="px-4 py-6 text-center text-sm text-slate-500">
                Belum ada aktivitas tercatat untuk tenant ini.
            </div>
            @endforelse
        </div>

        <div class="mt-4">
            {{ $auditLogs->links() }}
        </div>
    </main>
</div>
@endsection