<header
    class="sticky top-0 z-30 border-b border-gray-200 bg-white/95 backdrop-blur dark:border-gray-800 dark:bg-gray-900/95">
    <div class="flex h-20 items-center justify-between px-4 sm:px-6 lg:px-8">
        {{-- Left controls --}}
        <div class="flex items-center gap-3">
            {{-- Mobile sidebar toggle --}}
            <button
                type="button"
                @click="$store.sidebar.toggleMobile()"
                class="rounded-lg p-2.5 text-gray-500 hover:bg-gray-100 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-brand-500/30 lg:hidden dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-white"
                aria-label="Buka menu navigasi">
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
                        d="M4 6h16M4 12h16M4 18h16" />
                </svg>
            </button>

            {{-- Desktop sidebar toggle --}}
            <button
                type="button"
                @click="$store.sidebar.toggleExpanded()"
                class="hidden rounded-lg p-2.5 text-gray-500 hover:bg-gray-100 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-brand-500/30 lg:block dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-white"
                aria-label="Toggle sidebar">
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
                        d="M4 6h16M4 12h16M4 18h16" />
                </svg>
            </button>

            <div class="hidden sm:block">
                <p class="text-sm font-semibold text-gray-900 dark:text-white">
                    EduCore Platform
                </p>

                <p class="text-xs text-gray-500 dark:text-gray-400">
                    Administration Console
                </p>
            </div>
        </div>

        {{-- Right controls --}}
        <div class="flex items-center gap-2">
            {{-- Theme toggle --}}
            <button
                type="button"
                @click="$store.theme.toggle()"
                class="rounded-lg p-2.5 text-gray-500 hover:bg-gray-100 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-brand-500/30 dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-white"
                aria-label="Ganti tema">
                <svg
                    x-show="!$store.theme.isDark()"
                    class="h-5 w-5"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.8"
                    aria-hidden="true">
                    <circle cx="12" cy="12" r="4" />
                    <path
                        stroke-linecap="round"
                        d="M12 2v2m0 16v2M4.93 4.93l1.41 1.41m11.32 11.32 1.41 1.41M2 12h2m16 0h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41" />
                </svg>

                <svg
                    x-show="$store.theme.isDark()"
                    class="h-5 w-5"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.8"
                    aria-hidden="true">
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M21 12.8A8.5 8.5 0 1 1 11.2 3 6.5 6.5 0 0 0 21 12.8Z" />
                </svg>
            </button>

            {{-- User --}}
            <div
                class="hidden h-8 w-px bg-gray-200 sm:block dark:bg-gray-800"
                aria-hidden="true"></div>

            <div class="hidden text-right sm:block">
                <p class="text-sm font-medium text-gray-900 dark:text-white">
                    Platform Administrator
                </p>

                <p class="text-xs text-gray-500 dark:text-gray-400">
                    EduCore
                </p>
            </div>

            {{-- Logout --}}
            <form
                method="POST"
                action="{{ route('platform.logout') }}"
                class="ml-1">
                @csrf

                <button
                    type="submit"
                    class="rounded-lg p-2.5 text-gray-500 hover:bg-error-50 hover:text-error-600 focus:outline-none focus:ring-2 focus:ring-error-500/30 dark:text-gray-400 dark:hover:bg-error-500/10 dark:hover:text-error-400"
                    aria-label="Keluar"
                    title="Keluar">
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
                            d="M10 17l5-5-5-5m5 5H3m8-9h6a2 2 0 0 1 2 2v2m-8 10h6a2 2 0 0 0 2-2v-2" />
                    </svg>
                </button>
            </form>
        </div>
    </div>
</header>