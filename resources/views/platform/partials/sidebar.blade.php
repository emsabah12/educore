<aside
    id="platform-sidebar"
    x-cloak
    class="fixed inset-y-0 left-0 z-50 flex w-72 flex-col border-r border-gray-200 bg-white transition-transform duration-300 dark:border-gray-800 dark:bg-gray-900 lg:translate-x-0"
    :class="{
        'translate-x-0': $store.sidebar.isMobileOpen,
        '-translate-x-full': !$store.sidebar.isMobileOpen
    }"
    aria-label="Platform navigation">
    {{-- Logo / brand --}}
    <div class="flex h-20 shrink-0 items-center border-b border-gray-200 px-5 dark:border-gray-800">
        <a
            href="{{ route('platform.dashboard') }}"
            class="flex items-center gap-3"
            aria-label="EduCore Platform">
            <div
                class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-500 text-sm font-bold text-white">
                EC
            </div>

            <div class="min-w-0">
                <p class="truncate text-sm font-semibold text-gray-900 dark:text-white">
                    EduCore
                </p>

                <p class="truncate text-xs text-gray-500 dark:text-gray-400">
                    Platform Admin
                </p>
            </div>
        </a>

        {{-- Mobile close --}}
        <button
            type="button"
            @click="$store.sidebar.closeMobile()"
            class="ml-auto rounded-lg p-2 text-gray-500 hover:bg-gray-100 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-brand-500/30 lg:hidden dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-white"
            aria-label="Tutup menu navigasi">
            <svg
                class="h-5 w-5"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.8"
                aria-hidden="true">
                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M6 18 18 6M6 6l12 12" />
            </svg>
        </button>
    </div>

    {{-- Navigation --}}
    <nav class="custom-scrollbar flex-1 overflow-y-auto px-4 py-5">
        <p class="mb-3 px-3 text-xs font-semibold uppercase tracking-wider text-gray-400">
            Platform
        </p>

        <div class="space-y-1">
            {{-- Dashboard --}}
            <a
                href="{{ route('platform.dashboard') }}"
                @click="$store.sidebar.closeMobile()"
                class="menu-item {{ request()->routeIs('platform.dashboard') ? 'menu-item-active' : 'menu-item-inactive' }}"
                @if (request()->routeIs('platform.dashboard')) aria-current="page" @endif
                >
                <svg
                    class="h-5 w-5 shrink-0 {{ request()->routeIs('platform.dashboard') ? 'menu-item-icon-active' : 'menu-item-icon-inactive' }}"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.8"
                    aria-hidden="true">
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M3 13h8V3H3v10Zm10 8h8V3h-8v18ZM3 21h8v-6H3v6Z" />
                </svg>

                <span>Dashboard</span>
            </a>

            {{-- Tenants --}}
            <a
                href="{{ route('platform.tenants.index') }}"
                @click="$store.sidebar.closeMobile()"
                class="menu-item {{ request()->routeIs('platform.tenants.*') ? 'menu-item-active' : 'menu-item-inactive' }}"
                @if (request()->routeIs('platform.tenants.*')) aria-current="page" @endif
                >
                <svg
                    class="h-5 w-5 shrink-0 {{ request()->routeIs('platform.tenants.*') ? 'menu-item-icon-active' : 'menu-item-icon-inactive' }}"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.8"
                    aria-hidden="true">
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M4 21V5a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v16M4 21h16M8 7h2m4 0h2M8 11h2m4 0h2M8 15h2m4 0h2M9 21v-3a3 3 0 0 1 6 0v3" />
                </svg>

                <span>Tenant</span>
            </a>

            {{-- Roles --}}
            <a
                href="{{ route('platform.roles.index') }}"
                @click="$store.sidebar.closeMobile()"
                class="menu-item {{ request()->routeIs('platform.roles.*') ? 'menu-item-active' : 'menu-item-inactive' }}"
                @if (request()->routeIs('platform.roles.*')) aria-current="page" @endif
                >
                <svg
                    class="h-5 w-5 shrink-0 {{ request()->routeIs('platform.roles.*') ? 'menu-item-icon-active' : 'menu-item-icon-inactive' }}"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.8"
                    aria-hidden="true">
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M12 15a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm0 0v6m-4-2h8M19 8.5V5a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v3.5" />
                </svg>

                <span>Role &amp; Permission</span>
            </a>

            {{-- Plans & Add-ons --}}
            <a
                href="{{ route('platform.plans.index') }}"
                @click="$store.sidebar.closeMobile()"
                class="menu-item {{ request()->routeIs('platform.plans.*') || request()->routeIs('platform.addons.*') ? 'menu-item-active' : 'menu-item-inactive' }}"
                @if (request()->routeIs('platform.plans.*') || request()->routeIs('platform.addons.*')) aria-current="page" @endif
                >
                <svg
                    class="h-5 w-5 shrink-0 {{ request()->routeIs('platform.plans.*') || request()->routeIs('platform.addons.*') ? 'menu-item-icon-active' : 'menu-item-icon-inactive' }}"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.8"
                    aria-hidden="true">
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M4 7h16M4 7a2 2 0 0 1-2-2 2 2 0 0 1 2-2h16a2 2 0 0 1 2 2 2 2 0 0 1-2 2M4 7v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7M8 12h8" />
                </svg>

                <span>Paket &amp; Add-on</span>
            </a>

            {{-- Audit Logs --}}
            <a
                href="{{ route('platform.audit-logs.index') }}"
                @click="$store.sidebar.closeMobile()"
                class="menu-item {{ request()->routeIs('platform.audit-logs.*') ? 'menu-item-active' : 'menu-item-inactive' }}"
                @if (request()->routeIs('platform.audit-logs.*')) aria-current="page" @endif
                >
                <svg
                    class="h-5 w-5 shrink-0 {{ request()->routeIs('platform.audit-logs.*') ? 'menu-item-icon-active' : 'menu-item-icon-inactive' }}"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.8"
                    aria-hidden="true">
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M12 8v4l3 2m6-2a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                </svg>

                <span>Log Aktivitas</span>
            </a>
        </div>
    </nav>

    {{-- Sidebar footer --}}
    <div class="border-t border-gray-200 p-4 dark:border-gray-800">
        <div class="rounded-xl bg-gray-50 p-3 dark:bg-white/5">
            <p class="text-xs font-medium text-gray-900 dark:text-white">
                Platform Administration
            </p>

            <p class="mt-1 text-xs leading-5 text-gray-500 dark:text-gray-400">
                Kelola tenant dan konfigurasi platform EduCore.
            </p>
        </div>
    </div>
</aside>