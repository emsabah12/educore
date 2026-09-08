@extends('platform.layouts.app', ['title' => $addon->name . ' — EduCore Platform'])

@section('content')
<div class="min-h-screen">
    @include('platform.partials.nav')

    <main class="p-6 max-w-xl">
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-xl font-semibold">{{ $addon->name }}</h1>
                <p class="text-sm text-slate-500 font-mono">{{ $addon->code }}</p>
            </div>
            <a href="{{ route('platform.addons.index') }}" class="text-sm text-slate-500 hover:text-slate-800">
                &larr; Kembali
            </a>
        </div>

        @if (session('status'))
        <div class="mb-4 rounded-md bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-700">
            {{ session('status') }}
        </div>
        @endif

        <form method="POST" action="{{ route('platform.addons.update', $addon->id) }}" class="bg-white rounded-lg border border-slate-200 p-4 space-y-4">
            @csrf
            @method('PUT')

            <div>
                <label for="name" class="block text-sm font-medium text-slate-700">Nama Add-on</label>
                <input
                    type="text" name="name" id="name" value="{{ old('name', $addon->name) }}" required
                    class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
            </div>

            <div>
                <label for="description" class="block text-sm font-medium text-slate-700">Deskripsi</label>
                <textarea
                    name="description" id="description" rows="2"
                    class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">{{ old('description', $addon->description) }}</textarea>
            </div>

            <div>
                <label for="feature_id" class="block text-sm font-medium text-slate-700">Fitur yang Dibuka</label>
                <select
                    name="feature_id" id="feature_id" required
                    class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                    @foreach ($features as $feature)
                    <option value="{{ $feature->id }}" @selected(old('feature_id', $addon->feature_id) === $feature->id)>
                        {{ $feature->code }} — {{ $feature->name }}
                    </option>
                    @endforeach
                </select>
            </div>

            <label class="flex items-center gap-2 text-sm">
                <input
                    type="checkbox" name="is_active" value="1"
                    @checked(old('is_active', $addon->is_active))
                class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                >
                Add-on aktif (bisa dipilih tenant)
            </label>

            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                Simpan Perubahan
            </button>
        </form>
    </main>
</div>
@endsection