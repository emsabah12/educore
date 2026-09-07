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
                    class="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-red hover:bg-red-500"
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