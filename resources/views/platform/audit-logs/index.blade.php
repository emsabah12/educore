@extends('platform.layouts.app', ['title' => 'Log Aktivitas — EduCore Platform'])

@section('content')
<div class="min-h-screen">
    @include('platform.partials.nav')

    <main class="p-6">
        <h1 class="text-xl font-semibold mb-4">Log Aktivitas Platform</h1>

        <form method="GET" action="{{ route('platform.audit-logs.index') }}" class="flex flex-wrap items-end gap-3 mb-4">
            <div>
                <label for="event_type" class="block text-xs font-medium text-slate-500 mb-1">Jenis Kejadian</label>
                <select
                    name="event_type"
                    id="event_type"
                    class="rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <option value="">Semua</option>
                    @foreach ($eventTypes as $type)
                    <option value="{{ $type }}" @selected($selectedEventType===$type)>
                        {{ $type }}
                    </option>
                    @endforeach
                </select>
            </div>

            <button
                type="submit"
                class="rounded-md bg-slate-800 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">
                Filter
            </button>

            @if ($selectedEventType !== '' || $selectedTenantId !== '')
            <a href="{{ route('platform.audit-logs.index') }}" class="text-sm text-slate-500 hover:text-slate-800">
                Reset filter
            </a>
            @endif
        </form>

        <div class="bg-white rounded-lg border border-slate-200 divide-y divide-slate-100">
            @forelse ($logs as $log)
            <div class="px-4 py-3">
                <div class="flex items-start justify-between gap-4">
                    <p class="text-sm text-slate-900">{{ $log->description }}</p>
                    <span class="shrink-0 text-xs text-slate-400">
                        {{ \Illuminate\Support\Carbon::parse($log->created_at)->format('d M Y H:i') }}
                    </span>
                </div>
                <p class="text-xs text-slate-500 mt-1">
                    <span class="font-mono">{{ $log->event_type }}</span>
                    @if ($log->tenant_name !== null)
                    &middot; Tenant:
                    <a
                        href="{{ route('platform.audit-logs.index', ['tenant_id' => $log->tenant_id]) }}"
                        class="text-indigo-600 hover:underline">
                        {{ $log->tenant_name }}
                    </a>
                    @endif
                    @if ($log->actor_email !== null)
                    &middot; Oleh: {{ $log->actor_email }}
                    @endif
                </p>
            </div>
            @empty
            <div class="px-4 py-6 text-center text-sm text-slate-500">
                Tidak ada aktivitas yang cocok dengan filter ini.
            </div>
            @endforelse
        </div>

        <div class="mt-4">
            {{ $logs->links() }}
        </div>
    </main>
</div>
@endsection