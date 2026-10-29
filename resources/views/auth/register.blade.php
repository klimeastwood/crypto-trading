<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0b0e14">
    <title>Регистрация — {{ config('app.name') }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="mask-icon" href="{{ asset('favicon.svg') }}" color="#f7931a">
    <link rel="stylesheet" href="{{ asset('css/trading.css') }}">
</head>
<body>
<div class="login-page">
    <div class="card login-card register-card">
        <div class="login-brand">
            <span class="brand-mark">₿</span>
            <span class="brand-text">
                {{ config('app.name') }}
                <span class="brand-sub">crypto desk</span>
            </span>
        </div>

        <h1>Создать аккаунт</h1>
        <p class="card-sub">Регистрация занимает минуту и сразу открывает личный кабинет с торговым счётом в USD.</p>

        @if ($errors->any())
            <div class="flash flash-error">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form class="stack" method="POST" action="{{ route('register') }}">
            @csrf

            <label class="field">
                <span>Имя и фамилия</span>
                <input
                    class="input"
                    type="text"
                    name="name"
                    value="{{ old('name') }}"
                    autocomplete="name"
                    placeholder="Иван Петров"
                    required
                    autofocus
                >
            </label>

            <label class="field">
                <span>Email</span>
                <input
                    class="input"
                    type="email"
                    name="email"
                    value="{{ old('email') }}"
                    autocomplete="email"
                    placeholder="name@cryptoisk.com"
                    required
                >
            </label>

            <label class="field">
                <span>Телефон <span class="hint">необязательно</span></span>
                <input
                    class="input"
                    type="tel"
                    name="phone"
                    value="{{ old('phone') }}"
                    autocomplete="tel"
                    placeholder="+375 29 123-45-67"
                >
            </label>

            <label class="field">
                <span>Пароль</span>
                <input
                    class="input"
                    type="password"
                    name="password"
                    autocomplete="new-password"
                    placeholder="••••••••"
                    required
                >
                <span class="hint">Минимум 8 символов.</span>
            </label>

            <label class="field">
                <span>Повторите пароль</span>
                <input
                    class="input"
                    type="password"
                    name="password_confirmation"
                    autocomplete="new-password"
                    placeholder="••••••••"
                    required
                >
            </label>

            <label class="checkbox">
                <input type="checkbox" name="terms" value="1" @checked(old('terms'))>
                <span>Согласен на обработку персональных данных и с условиями использования платформы.</span>
            </label>

            <button class="btn btn-primary" type="submit">Зарегистрироваться</button>
        </form>

        <p class="login-footer">
            Уже есть аккаунт? <a href="{{ route('login') }}">Войти в личный кабинет</a>.
        </p>
    </div>
</div>
</body>
</html>
