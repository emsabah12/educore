@extends('platform.layouts.app', ['title' => 'Dashboard — EduCore Platform'])

@section('content')
<div class="min-h-screen">
    @include('platform.partials.nav')


    <main class="p-6">
        <h1 class="text-xl font-semibold">
            Selamat datang, {{ auth('web')->user()?->person?->name ?? auth('web')->user()?->email }}
        </h1>
        <p class="text-slate-500 mt-1">
            <a href="{{ route('platform.tenants.index') }}" class="text-indigo-600 hover:underline">
                Kelola daftar tenant &rarr;
            </a>
        </p>
    </main>
</div>
@endsection