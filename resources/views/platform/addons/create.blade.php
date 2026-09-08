@extends('platform.layouts.app', ['title' => 'Add-on Baru — EduCore Platform'])

@section('content')
<div class="min-h-screen">
    @include('platform.partials.nav')

    <main class="p-6 max-w-xl">
        <h1 class="text-xl font-semibold mb-6">Buat Add-on Baru</h1>

        @if ($errors->any())
        <div class="mb-4 rounded-md bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">
            <ul class="list-disc list-inside space-y-1">
                @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
        @endif

        <form method="POST" action="{{ route('platform.addons.store') }}" class="space-y-4">
            @csrf

            <div>
                <label for="code" class="block text-sm font-medium text-slate-700">Kode Add-on</label>
                <input
                    type="text" name="code" id="code" value="{{ old('code') }}" required
                    placeholder="contoh: custom-roles-addon"
                    class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm font-mono">
                <p class="mt-1 text-xs text-slate-500">Tidak bisa diubah lagi setelah dibuat.</p>
            </div>

            <div>
                <label for="name" class="block text-sm font-medium text-slate-700">Nama Add-on</label>
                <input
                    type="text" name="name" id="name" value="{{ old('name') }}" required
                    class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
            </div>

            <div>
                <label for="description" class="block text-sm font-medium text-slate-700">Deskripsi (opsional)</label>
                <textarea
                    name="description" id="description" rows="2"
                    class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">{{ old('description') }}</textarea>
            </div>

            <div>
                <label for="feature_id" class="block text-sm font-medium text-slate-700">Fitur yang Dibuka</label>
                <select
                    name="feature_id" id="feature_id" required
                    class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                    <option value="">— Pilih fitur —</option>
                    @foreach ($features as $feature)
                    <option value="{{ $feature->id }}" @selected(old('feature_id')===$feature->id)>
                        {{ $feature->code }} — {{ $feature->name }}
                    </option>
                    @endforeach
                </select>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                    Buat Add-on
                </button>
                <a href="{{ route('platform.addons.index') }}" class="text-sm text-slate-500 hover:text-slate-800">Batal</a>
            </div>
        </form>
    </main>
</div>
@endsection