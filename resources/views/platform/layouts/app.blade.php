<!DOCTYPE html>
<html
    lang="id"
    dir="ltr"
    x-data
    x-init="$store.theme.init()"
    class="h-full">

<head>
    <meta charset="utf-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1">

    <title>{{ $title ?? 'EduCore Platform' }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body
    class="min-h-full bg-gray-50 text-gray-900 antialiased dark:bg-gray-950 dark:text-white">
    <div
        class="min-h-screen"
        x-data>
        {{-- Mobile backdrop --}}
        <div
            x-cloak
            x-show="$store.sidebar.isMobileOpen"
            x-transition.opacity
            @click="$store.sidebar.closeMobile()"
            class="fixed inset-0 z-40 bg-gray-900/50 lg:hidden"
            aria-hidden="true"></div>

        {{-- Sidebar --}}
        @include('platform.partials.sidebar')

        {{-- Main application shell --}}
        <div
            class="min-h-screen transition-[margin] duration-300"
            :class="{
                'lg:ml-72': $store.sidebar.isExpanded,
                'lg:ml-20': !$store.sidebar.isExpanded
            }">
            {{-- Header --}}
            @include('platform.partials.nav')

            {{-- Main content --}}
            <main class="px-4 py-6 sm:px-6 lg:px-8">
                <div class="mx-auto w-full max-w-7xl">
                    @if (session('status'))
                    <div
                        role="status"
                        class="mb-6 rounded-xl border border-success-200 bg-success-50 px-4 py-3 text-sm font-medium text-success-700 dark:border-success-900/40 dark:bg-success-950/30 dark:text-success-400">
                        {{ session('status') }}
                    </div>
                    @endif

                    @if ($errors->any())
                    <div
                        role="alert"
                        class="mb-6 rounded-xl border border-error-200 bg-error-50 px-4 py-3 text-sm text-error-700 dark:border-error-900/40 dark:bg-error-950/30 dark:text-error-400">
                        <p class="font-semibold">
                            Terdapat kesalahan pada permintaan Anda.
                        </p>

                        <ul class="mt-2 list-disc space-y-1 pl-5">
                            @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                    @endif

                    @yield('content')
                </div>
            </main>
        </div>
    </div>
</body>

</html>