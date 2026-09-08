@extends('platform.layouts.app', ['title' => 'Paket Baru — EduCore Platform'])

@section('content')
<div class="min-h-screen">
    @include('platform.partials.nav')

    <main class="p-6 max-w-xl">
        <h1 class="text-xl font-semibold mb-6">Buat Paket Baru</h1>

        @if ($errors->any())
        <div class="mb-4 rounded-md bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">
            <ul class="list-disc list-inside space-y-1">
                @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
        @endif

        <form method="POST" action="{{ route('platform.plans.store') }}" class="space-y-4">
            @csrf

            <div>
                <label for="code" class="block text-sm font-medium text-slate-700">Kode Paket</label>
                <input
                    type="text" name="code" id="code" value="{{ old('code') }}" required
                    placeholder="contoh: premium"
                    class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm font-mono">
                <p class="mt-1 text-xs text-slate-500">Tidak bisa diubah lagi setelah dibuat.</p>
            </div>

            <div>
                <label for="name" class="block text-sm font-medium text-slate-700">Nama Paket</label>
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
                <label for="grace_period_days" class="block text-sm font-medium text-slate-700">
                    Masa Tenggang (hari)
                </label>
                <input
                    type="number" name="grace_period_days" id="grace_period_days"
                    value="{{ old('grace_period_days', 30) }}" required min="1" max="365"
                    class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                <p class="mt-1 text-xs text-slate-500">
                    Berapa lama fitur/add-on yang dicabut tetap read-only sebelum disembunyikan.
                </p>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                    Buat Paket
                </button>
                <a href="{{ route('platform.plans.index') }}" class="text-sm text-slate-500 hover:text-slate-800">Batal</a>
            </div>
        </form>
    </main>
</div>
@endsection