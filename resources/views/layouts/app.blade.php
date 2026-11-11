<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'POS') · Jean Rose</title>

    {{-- Fonts: Nunito (body), Poppins (headings, buttons), Parisienne (wordmark) --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800&family=Poppins:wght@500;600;700;800&family=Parisienne&display=swap" rel="stylesheet">

    {{-- Bootstrap 5 from a CDN (no npm, no Vite) --}}
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    {{-- Our colors and Bootstrap overrides. Must come AFTER Bootstrap. --}}
    <link href="{{ asset('css/jeanrose.css') }}" rel="stylesheet">

    @stack('styles')
</head>
<body>
    @include('partials.header')

    <main class="jr-main">
        @include('partials.flash')

        @yield('content')
    </main>

    {{-- Bootstrap JS (needed for the Menu collapse, modals and dropdowns) --}}
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    @stack('scripts')
</body>
</html>
