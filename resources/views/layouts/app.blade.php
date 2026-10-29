<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#0b0e14">
    <title>@yield('title', 'Личный кабинет') — {{ config('app.name') }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="mask-icon" href="{{ asset('favicon.svg') }}" color="#f7931a">
    <link rel="stylesheet" href="{{ asset('css/trading.css') }}">
</head>
<body>
<header class="topbar">
    <div class="topbar-inner">
        <a class="brand" href="{{ route('dashboard') }}">
            <span class="brand-mark">₿</span>
            <span class="brand-text">
                {{ config('app.name') }}
                <span class="brand-sub">crypto desk</span>
            </span>
        </a>

        <nav class="nav">
            <a class="nav-link is-active" href="{{ route('dashboard') }}">Кабинет</a>
            <span class="user-chip" title="{{ auth()->user()->email }}">{{ auth()->user()->name }}</span>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="btn btn-ghost btn-sm" type="submit">Выйти</button>
            </form>
        </nav>
    </div>
</header>

<main class="container">
    @include('partials.flash')
    @yield('content')
</main>

<footer class="footer">
    {{ config('app.name') }} — торговля криптоактивами связана с риском потери капитала.
    Котировки: CoinGecko.
</footer>

@stack('scripts')
</body>
</html>
