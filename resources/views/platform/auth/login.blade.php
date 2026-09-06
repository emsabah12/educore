@extends('platform.layouts.app', ['title' => 'Masuk — EduCore Platform'])

@section('content')
<div class="min-h-screen flex items-center justify-center px-4">
    <div class="w-full max-w-sm">
        <h1 class="text-2xl font-semibold text-center mb-1">EduCore Platform</h1>
        <p class="text-sm text-slate-500 text-center mb-6">Masuk sebagai Superadmin</p>

        @if ($errors->any())
        <div class="mb-4 rounded-md bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">
            {{ $errors->first() }}
        </div>
        @endif

        <form method="POST" action="{{ route('platform.login.attempt') }}" class="space-y-4">
            @csrf

            <div>
                <label for="identifier" class="block text-sm font-medium text-slate-700">
                    Email atau Username
                </label>
                <input
                    type="text"
                    name="identifier"
                    id="identifier"
                    value="{{ old('identifier') }}"
                    required
                    autofocus
                    class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-slate-700">
                    Kata Sandi
                </label>
                <input
                    type="password"
                    name="password"
                    id="password"
                    required
                    class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
            </div>

            <button
                type="submit"
                class="w-full rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                Masuk
            </button>
        </form>
    </div>
</div>
@endsection