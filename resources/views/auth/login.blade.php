<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0b0e14">
    <title>Вход — {{ config('app.name') }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="mask-icon" href="{{ asset('favicon.svg') }}" color="#f7931a">
    <link rel="stylesheet" href="{{ asset('css/trading.css') }}">
</head>
<body>
<div class="login-page">
    <div class="card login-card">
        <div class="login-brand">
            <span class="brand-mark">₿</span>
            <span class="brand-text">
                {{ config('app.name') }}
                <span class="brand-sub">crypto desk</span>
            </span>
        </div>

        <h1>Вход в личный кабинет</h1>
        <p class="card-sub">Введите e-mail и пароль от личного кабинета.</p>

        @if ($errors->any())
            <div class="flash flash-error">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form class="stack" method="POST" action="{{ route('login') }}">
            @csrf

            <label class="field">
                <span>Email</span>
                <input
                    class="input"
                    type="email"
                    name="email"
                    value="{{ old('email') }}"
                    autocomplete="username"
                    placeholder="name@cryptoisk.com"
                    required
                    autofocus
                >
            </label>

            <label class="field">
                <span>Пароль</span>
                <input
                    class="input"
                    type="password"
                    name="password"
                    autocomplete="current-password"
                    placeholder="••••••••"
                    required
                >
            </label>

            <button class="btn btn-primary" type="submit">Войти</button>
        </form>

        <p class="login-footer">
            Нет аккаунта? <a href="{{ route('register') }}">Зарегистрируйтесь</a> — кабинет откроется сразу.
        </p>
    </div>
</div>
</body>
</html>
