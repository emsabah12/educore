@extends('platform.layouts.app', ['title' => 'Tenant Baru — EduCore Platform'])

@section('content')
<div class="min-h-screen">
    @include('platform.partials.nav')

    <main class="p-6 max-w-xl">
        <h1 class="text-xl font-semibold mb-6">Daftarkan Tenant Baru</h1>

        @if ($errors->any())
        <div class="mb-4 rounded-md bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">
            <ul class="list-disc list-inside space-y-1">
                @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
        @endif

        <form method="POST" action="{{ route('platform.tenants.store') }}" class="space-y-6">
            @csrf

            <fieldset class="space-y-4">
                <legend class="text-sm font-semibold text-slate-700 mb-2">Informasi Tenant</legend>

                <div>
                    <label for="name" class="block text-sm font-medium text-slate-700">
                        Nama Sekolah/Institusi
                    </label>
                    <input
                        type="text"
                        name="name"
                        id="name"
                        value="{{ old('name') }}"
                        required
                        class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                </div>

                <div>
                    <label for="subdomain" class="block text-sm font-medium text-slate-700">
                        Subdomain
                    </label>
                    <input
                        type="text"
                        name="subdomain"
                        id="subdomain"
                        value="{{ old('subdomain') }}"
                        required
                        placeholder="contoh: sma-negeri-1"
                        class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                    <p class="mt-1 text-xs text-slate-500">Huruf kecil, angka, dan tanda hubung saja.</p>
                </div>
            </fieldset>

            <fieldset class="space-y-4 pt-4 border-t border-slate-200">
                <legend class="text-sm font-semibold text-slate-700 mb-2">Administrator Awal</legend>

                <div>
                    <label for="admin_name" class="block text-sm font-medium text-slate-700">
                        Nama Admin
                    </label>
                    <input
                        type="text"
                        name="admin_name"
                        id="admin_name"
                        value="{{ old('admin_name') }}"
                        required
                        class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                </div>

                <div>
                    <label for="admin_email" class="block text-sm font-medium text-slate-700">
                        Email Admin
                    </label>
                    <input
                        type="email"
                        name="admin_email"
                        id="admin_email"
                        value="{{ old('admin_email') }}"
                        required
                        class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                </div>

                <div>
                    <label for="admin_password" class="block text-sm font-medium text-slate-700">
                        Kata Sandi Admin
                    </label>
                    <input
                        type="password"
                        name="admin_password"
                        id="admin_password"
                        required
                        minlength="8"
                        class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                    <p class="mt-1 text-xs text-slate-500">Minimal 8 karakter.</p>
                </div>
            </fieldset>

            <div class="flex items-center gap-3">
                <button
                    type="submit"
                    class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                    Daftarkan Tenant
                </button>
                <a href="{{ route('platform.tenants.index') }}" class="text-sm text-slate-500 hover:text-slate-800">
                    Batal
                </a>
            </div>
        </form>
    </main>
</div>
@endsection