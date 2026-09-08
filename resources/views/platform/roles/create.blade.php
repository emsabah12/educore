@extends('platform.layouts.app', ['title' => 'Role & Permission — EduCore Platform'])

@section('content')
<div class="min-h-screen">
    @include('platform.partials.nav')

    <main class="p-6">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h1 class="text-xl font-semibold">Katalog Role &amp; Permission</h1>
                <p class="text-sm text-slate-500 mt-1">
                    Katalog global lintas tenant — bukan pengaturan role di dalam satu tenant tertentu.
                </p>
            </div>
            <a
                href="{{ route('platform.roles.create') }}"
                class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                + Role Baru
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
                        <th class="px-4 py-2 text-left text-xs font-medium text-slate-500 uppercase">Nama</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-slate-500 uppercase">Label</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-slate-500 uppercase">Jumlah Permission</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($roles as $role)
                    <tr>
                        <td class="px-4 py-3 text-sm font-mono text-slate-900">{{ $role->name }}</td>
                        <td class="px-4 py-3 text-sm text-slate-700">{{ $role->display_name }}</td>
                        <td class="px-4 py-3 text-sm text-slate-500">{{ $role->permissions_count }}</td>
                        <td class="px-4 py-3 text-sm text-right">
                            <a href="{{ route('platform.roles.show', $role->id) }}" class="text-indigo-600 hover:underline">
                                Kelola
                            </a>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="4" class="px-4 py-6 text-center text-sm text-slate-500">
                            Belum ada role terdaftar.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </main>
</div>
@endsection