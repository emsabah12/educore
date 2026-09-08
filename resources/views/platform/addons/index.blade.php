@extends('platform.layouts.app', ['title' => 'Add-on — EduCore Platform'])

@section('content')
<div class="min-h-screen">
    @include('platform.partials.nav')

    <main class="p-6">
        <div class="flex items-center justify-between mb-4">
            <h1 class="text-xl font-semibold">Add-on</h1>
            <a
                href="{{ route('platform.addons.create') }}"
                class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                + Add-on Baru
            </a>
        </div>

        @if (session('status'))
        <div class="mb-4 rounded-md bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-700">
            {{ session('status') }}
        </div>
        @endif

        <div class="bg-white rounded-lg border border-slate-200 overflow-hidden">
            <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-slate-500 uppercase">Kode</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-slate-500 uppercase">Nama</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-slate-500 uppercase">Fitur</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-slate-500 uppercase">Status</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($addons as $addon)
                    <tr>
                        <td class="px-4 py-3 text-sm font-mono text-slate-900">{{ $addon->code }}</td>
                        <td class="px-4 py-3 text-sm text-slate-700">{{ $addon->name }}</td>
                        <td class="px-4 py-3 text-sm text-slate-500 font-mono">{{ $addon->feature->code }}</td>
                        <td class="px-4 py-3 text-sm">
                            @if ($addon->is_active)
                            <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-1 text-xs font-medium text-emerald-700">Aktif</span>
                            @else
                            <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-1 text-xs font-medium text-slate-600">Nonaktif</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm text-right">
                            <a href="{{ route('platform.addons.show', $addon->id) }}" class="text-indigo-600 hover:underline">
                                Kelola
                            </a>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="5" class="px-4 py-6 text-center text-sm text-slate-500">
                            Belum ada add-on terdaftar.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </main>
</div>
@endsection