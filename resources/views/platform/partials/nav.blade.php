<nav class="bg-white border-b border-slate-200 px-6 py-4 flex items-center justify-between">
    <div class="flex items-center gap-6">
        <span class="font-semibold">EduCore Platform</span>

        <a
            href="{{ route('platform.dashboard') }}"
            class="text-sm {{ request()->routeIs('platform.dashboard') ? 'font-semibold text-slate-900' : 'text-slate-600 hover:text-slate-900' }}">
            Dashboard
        </a>

        <a
            href="{{ route('platform.tenants.index') }}"
            class="text-sm {{ request()->routeIs('platform.tenants.*') ? 'font-semibold text-slate-900' : 'text-slate-600 hover:text-slate-900' }}">
            Tenant
        </a>
    </div>

    <form method="POST" action="{{ route('platform.logout') }}">
        @csrf
        <button type="submit" class="text-sm text-slate-500 hover:text-slate-800">
            Keluar
        </button>
    </form>
</nav>