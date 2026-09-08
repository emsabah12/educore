@extends('platform.layouts.app', ['title' => $role->display_name . ' — EduCore Platform'])

@section('content')
<div class="min-h-screen">
    @include('platform.partials.nav')

    <main class="p-6 max-w-3xl">
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-xl font-semibold">{{ $role->display_name }}</h1>
                <p class="text-sm text-slate-500 font-mono">{{ $role->name }}</p>
                @if ($role->description)
                <p class="text-sm text-slate-500 mt-1">{{ $role->description }}</p>
                @endif
            </div>
            <a href="{{ route('platform.roles.index') }}" class="text-sm text-slate-500 hover:text-slate-800">
                &larr; Kembali
            </a>
        </div>

        @if (session('status'))
        <div class="mb-4 rounded-md bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-700">
            {{ session('status') }}
        </div>
        @endif

        <form method="POST" action="{{ route('platform.roles.update', $role->id) }}">
            @csrf
            @method('PUT')

            <div class="space-y-6">
                @forelse ($permissionsByModule as $module => $permissions)
                <div class="bg-white rounded-lg border border-slate-200 p-4">
                    <h2 class="text-sm font-semibold text-slate-700 mb-3">{{ $module }}</h2>
                    <div class="space-y-2">
                        @foreach ($permissions as $permission)
                        <label class="flex items-start gap-2 text-sm">
                            <input
                                type="checkbox"
                                name="permission_ids[]"
                                value="{{ $permission->id }}"
                                @checked(in_array($permission->id, $assignedPermissionIds, true))
                            class="mt-0.5 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                            >
                            <span>
                                <span class="font-mono text-slate-900">{{ $permission->name }}</span>
                                <span class="text-slate-500"> — {{ $permission->display_name }}</span>
                            </span>
                        </label>
                        @endforeach
                    </div>
                </div>
                @empty
                <p class="text-sm text-slate-500">Belum ada permission terdaftar di katalog.</p>
                @endforelse
            </div>

            <div class="mt-6">
                <button
                    type="submit"
                    class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                    Simpan Permission
                </button>
            </div>
        </form>
    </main>
</div>
@endsection