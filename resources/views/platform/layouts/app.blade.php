<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ $title ?? 'EduCore Platform' }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="bg-slate-50 text-slate-900 antialiased">
    @if (session('status'))
    <div class="bg-emerald-50 text-emerald-800 text-sm px-4 py-2 text-center">
        {{ session('status') }}
    </div>
    @endif

    @yield('content')
</body>

</html>