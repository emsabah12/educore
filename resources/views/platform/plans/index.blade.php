@extends('platform.layouts.app', ['title' => 'Paket Subscription — EduCore Platform'])

@section('content')
<div class="min-h-screen">
    @include('platform.partials.nav')

    <main class="p-6">
        <div class="flex items-center justify-between mb-4">
            <h1 class="text-xl font-semibold">Paket Subscription</h1>
            <a
                href="{{ route('platform.plans.create') }}"
                class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                + Paket Baru
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
                        <th class="px-4 py-2 text-left text-xs font-medium text-slate-500 uppercase">Masa Tenggang</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-slate-500 uppercase">Jumlah Fitur</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($plans as $plan)
                    <tr>
                        <td class="px-4 py-3 text-sm font-mono text-slate-900">{{ $plan->code }}</td>
                        <td class="px-4 py-3 text-sm text-slate-700">{{ $plan->name }}</td>
                        <td class="px-4 py-3 text-sm text-slate-500">{{ $plan->grace_period_days }} hari</td>
                        <td class="px-4 py-3 text-sm text-slate-500">{{ $plan->features_count }}</td>
                        <td class="px-4 py-3 text-sm text-right">
                            <a href="{{ route('platform.plans.show', $plan->id) }}" class="text-indigo-600 hover:underline">
                                Kelola
                            </a>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="5" class="px-4 py-6 text-center text-sm text-slate-500">
                            Belum ada paket terdaftar.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </main>
</div>
@endsection