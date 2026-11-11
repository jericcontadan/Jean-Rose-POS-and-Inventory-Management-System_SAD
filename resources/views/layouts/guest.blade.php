<!DOCTYPE html>
<html lang="en">
<head>
    {{-- Layout for pages used before logging in (login, later forgot password). No header bar. --}}
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Log in') · Jean Rose</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800&family=Poppins:wght@500;600;700;800&family=Parisienne&display=swap" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="{{ asset('css/jeanrose.css') }}" rel="stylesheet">
</head>
<body>
    @yield('content')

    @stack('scripts')
</body>
</html>
