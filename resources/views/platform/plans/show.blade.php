@extends('platform.layouts.app', ['title' => $plan->name . ' — EduCore Platform'])

@section('content')
<div class="min-h-screen">
    @include('platform.partials.nav')

    <main class="p-6 max-w-2xl">
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-xl font-semibold">{{ $plan->name }}</h1>
                <p class="text-sm text-slate-500 font-mono">{{ $plan->code }}</p>
            </div>
            <a href="{{ route('platform.plans.index') }}" class="text-sm text-slate-500 hover:text-slate-800">
                &larr; Kembali
            </a>
        </div>

        @if (session('status'))
        <div class="mb-4 rounded-md bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-700">
            {{ session('status') }}
        </div>
        @endif

        <form method="POST" action="{{ route('platform.plans.update', $plan->id) }}" class="space-y-6">
            @csrf
            @method('PUT')

            <div class="bg-white rounded-lg border border-slate-200 p-4 space-y-4">
                <div>
                    <label for="name" class="block text-sm font-medium text-slate-700">Nama Paket</label>
                    <input
                        type="text" name="name" id="name" value="{{ old('name', $plan->name) }}" required
                        class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                </div>

                <div>
                    <label for="description" class="block text-sm font-medium text-slate-700">Deskripsi</label>
                    <textarea
                        name="description" id="description" rows="2"
                        class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">{{ old('description', $plan->description) }}</textarea>
                </div>

                <div>
                    <label for="grace_period_days" class="block text-sm font-medium text-slate-700">
                        Masa Tenggang (hari)
                    </label>
                    <input
                        type="number" name="grace_period_days" id="grace_period_days"
                        value="{{ old('grace_period_days', $plan->grace_period_days) }}" required min="1" max="365"
                        class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                </div>

                <label class="flex items-center gap-2 text-sm">
                    <input
                        type="checkbox" name="is_active" value="1"
                        @checked(old('is_active', $plan->is_active))
                    class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                    >
                    Paket aktif (bisa dipilih tenant)
                </label>
            </div>

            <div class="bg-white rounded-lg border border-slate-200 p-4">
                <h2 class="text-sm font-semibold text-slate-700 mb-3">Fitur Bawaan Paket</h2>
                <div class="space-y-2">
                    @forelse ($allFeatures as $feature)
                    <label class="flex items-start gap-2 text-sm">
                        <input
                            type="checkbox" name="feature_ids[]" value="{{ $feature->id }}"
                            @checked(in_array($feature->id, $assignedFeatureIds, true))
                        class="mt-0.5 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                        >
                        <span>
                            <span class="font-mono text-slate-900">{{ $feature->code }}</span>
                            <span class="text-slate-500"> — {{ $feature->name }}</span>
                        </span>
                    </label>
                    @empty
                    <p class="text-sm text-slate-500">Belum ada fitur terdaftar di katalog.</p>
                    @endforelse
                </div>
            </div>

            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                Simpan Perubahan
            </button>
        </form>
    </main>
</div>
@endsection