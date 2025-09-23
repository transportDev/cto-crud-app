<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'CTO Panel')</title>
    @vite(['resources/css/app.css','resources/js/app.js'])
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=poppins:400,500,600&display=swap" rel="stylesheet" />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex">
    <meta name="color-scheme" content="dark">
    @stack('head')
</head>

<body>
    <div class="mx-auto p-4">
        <div class="grid grid-cols-1 md:grid-cols-[240px_minmax(0,1fr)] gap-4 items-start">
            @include('partials.dashboard-sidebar')
            <div>
                @yield('content')
            </div>
        </div>
    </div>
    @stack('scripts')
</body>

</html>