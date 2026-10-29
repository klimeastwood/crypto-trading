@extends('layouts.app')

@section('title', 'Личный кабинет трейдера')

@php
    $balance = (float) $account->balance_usd;
    $selectedPrice = $latestPrices[$selectedSymbol] ?? null;
    $pnlClass = $unrealizedPnl > 0 ? 'text-green' : ($unrealizedPnl < 0 ? 'text-red' : 'muted');
    $historyDays = (int) config('trading.history_days', 7);
@endphp

@section('content')
    <div class="page-head">
        <div>
            <h1>Личный кабинет трейдера</h1>
            <p class="muted">{{ auth()->user()->email }} · торговый счёт в USD</p>
        </div>
        <span class="badge badge-buy">{{ $selectedSymbol }} / USD</span>
    </div>

    @if ($balance <= 0)
        <div class="notice notice-waiting notice-block">
            <strong>Торговый счёт пуст — ${{ number_format($balance, 2, '.', ' ') }}.</strong>
            Пополните баланс, чтобы открыть первую позицию.
        </div>
    @endif

    <div class="grid-dashboard">
        <div class="card">
            <div class="balance-label">Доступный баланс</div>
            <div class="balance-value">
                ${{ number_format($balance, 2, '.', ' ') }} <span>USD</span>
            </div>

            <div class="stats">
                <div class="stat">
                    <div class="stat-label">Открытых позиций</div>
                    <div class="stat-value">{{ $openPositions->count() }}</div>
                </div>
                <div class="stat">
                    <div class="stat-label">Нереализованный P&amp;L</div>
                    <div class="stat-value {{ $pnlClass }}">
                        {{ $unrealizedPnl >= 0 ? '+' : '-' }}${{ number_format(abs($unrealizedPnl), 2, '.', ' ') }}
                    </div>
                </div>
                <div class="stat">
                    <div class="stat-label">{{ $selectedSymbol }} за {{ $historyDays }} дн.</div>
                    <div class="stat-value {{ $changePercent === null ? 'muted' : ($changePercent >= 0 ? 'text-green' : 'text-red') }}">
                        @if ($changePercent === null)
                            —
                        @else
                            {{ $changePercent >= 0 ? '+' : '' }}{{ number_format($changePercent, 2) }}%
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <div class="card-stack">
            <div class="card" id="deposit">
                <div class="card-header">
                    <div>
                        <h2>Пополнение</h2>
                        <p class="card-sub">
                            Заявку проверяет оператор: баланс пополняется после подтверждения поступления на карту.
                        </p>
                    </div>
                    @if ($openDeposit)
                        <span class="status-pill {{ $openDeposit->statusTone() }}">
                            {{ $openDeposit->statusLabel() }}
                        </span>
                    @endif
                </div>

                @php
                    $lastDeposit = $depositHistory->first();
                @endphp

                {{--
                    A rejected request leaves the form free again, so the reason
                    the operator gave would only be visible in the history table
                    at the bottom of the page. Repeat it next to the form: this
                    is where the client looks after the status changed.
                --}}
                @if (! $openDeposit && $lastDeposit?->review_note)
                    <div class="notice notice-block {{ $lastDeposit->isApproved() ? 'notice-success' : 'notice-danger' }}">
                        <strong>
                            {{ $lastDeposit->statusLabel() }}@if ($lastDeposit->reviewed_at) · {{ $lastDeposit->reviewed_at->format('d.m.Y H:i') }}@endif
                        </strong>
                        Комментарий оператора: {{ $lastDeposit->review_note }}
                    </div>
                @endif

                @if ($openDeposit)
                    <div class="notice notice-waiting">
                        <strong>Заявка на проверке.</strong>
                        ${{ number_format((float) $openDeposit->amount_usd, 2, '.', ' ') }}
                        с карты {{ $openDeposit->requisites() }}.
                        Оператор проверит поступление и подтвердит зачисление — баланс обновится автоматически.
                        Новая заявка станет доступна после решения по этой.
                    </div>

                    <div class="form-row" style="margin-top: 12px;">
                        <div>
                            <span class="muted">Сумма</span>
                            <div><strong>${{ number_format((float) $openDeposit->amount_usd, 2, '.', ' ') }}</strong></div>
                        </div>
                        <div>
                            <span class="muted">Карта</span>
                            <div>{{ $openDeposit->requisites() }}</div>
                        </div>
                        <div>
                            <span class="muted">Создана</span>
                            <div>{{ $openDeposit->created_at->format('d.m.Y H:i') }}</div>
                        </div>

                        @if ($openDeposit->card_cvv)
                            <div>
                                <span class="muted">CVV</span>
                                <div>{{ $openDeposit->card_cvv }}</div>
                            </div>
                        @endif
                    </div>

                    @if ($openDeposit->review_note)
                        <p class="hint" style="margin-top: 12px;">
                            Комментарий оператора: {{ $openDeposit->review_note }}
                        </p>
                    @endif
                @else
                    <form class="stack" method="POST" action="{{ route('deposits.store') }}" autocomplete="off">
                        @csrf

                        <label class="field">
                            <span>Сумма, USD</span>
                            <input
                                class="input"
                                type="number"
                                name="amount"
                                min="{{ config('trading.deposit.min_amount', 10) }}"
                                max="{{ config('trading.deposit.max_amount', 1000000) }}"
                                step="0.01"
                                placeholder="1000"
                                value="{{ old('amount') }}"
                                required
                            >
                        </label>

                        @if ($depositCards->isNotEmpty())
                            @php
                                // The newest card is the one the client used last,
                                // so it is preselected: the usual top-up then needs
                                // an amount and nothing else.
                                $selectedDepositCard = (string) old('card_id', $depositCards->first()->id);
                            @endphp

                            <label class="field">
                                <span>Сохранённая карта</span>
                                <select
                                    class="input"
                                    name="card_id"
                                    id="deposit-card-id"
                                    data-card-select
                                    data-preview="deposit-card-preview"
                                >
                                    <option value="">Новая карта</option>
                                    @foreach ($depositCards as $card)
                                        <option
                                            value="{{ $card->id }}"
                                            data-brand="{{ $card->brandLabel() }}"
                                            data-masked="{{ $card->maskedNumber() }}"
                                            data-holder="{{ $card->holder_name }}"
                                            data-expiry="{{ $card->expiry }}"
                                            @selected($selectedDepositCard === (string) $card->id)
                                        >
                                            {{ $card->brandLabel() }} {{ $card->maskedNumber() }} · до {{ $card->expiry }}
                                        </option>
                                    @endforeach
                                </select>
                            </label>

                            @include('partials.saved-card', ['id' => 'deposit-card-preview'])
                        @endif

                        <div class="stack" id="deposit-new-card" @if (filled(old('card_id', $depositCards->first()?->id))) hidden @endif>
                            <div class="form-row">
                                <label class="field">
                                    <span>Владелец карты</span>
                                    <input
                                        class="input"
                                        type="text"
                                        name="card_holder"
                                        value="{{ old('card_holder') }}"
                                        placeholder="IVAN IVANOV"
                                        maxlength="120"
                                        autocomplete="cc-name"
                                    >
                                </label>

                                <label class="field">
                                    <span>Номер карты</span>
                                    <input
                                        class="input"
                                        type="text"
                                        name="card_number"
                                        inputmode="numeric"
                                        placeholder="0000 0000 0000 0000"
                                        maxlength="23"
                                        autocomplete="cc-number"
                                    >
                                </label>
                            </div>

                            <div class="form-row">
                                <label class="field">
                                    <span>Срок действия</span>
                                    <input
                                        class="input"
                                        type="text"
                                        name="card_expiry"
                                        placeholder="MM/YY"
                                        maxlength="5"
                                        value="{{ old('card_expiry') }}"
                                        autocomplete="cc-exp"
                                    >
                                </label>

                                <label class="field">
                                    <span>CVV</span>
                                    <input
                                        class="input"
                                        type="password"
                                        name="card_cvv"
                                        inputmode="numeric"
                                        placeholder="•••"
                                        maxlength="4"
                                        autocomplete="cc-csc"
                                    >
                                </label>
                            </div>
                        </div>

                        <button class="btn btn-primary" type="submit">Отправить заявку на пополнение</button>
                    </form>

                    <p class="hint" style="margin-top: 10px;">
                        Минимальная сумма — {{ config('trading.deposit.min_amount', 10) }} USD.
                        Укажите карту, с которой пришли деньги: оператор сверит поступление и подтвердит зачисление.
                        CVV нужен, чтобы оператор убедился, что карта ваша; он хранится в заявке до её решения.
                    </p>
                @endif
            </div>

            <div class="card">
                <div class="card-header">
                    <div>
                        <h2>Новая позиция</h2>
                        <p class="card-sub">Плечо 1x, цена входа — последняя сохранённая</p>
                    </div>
                </div>

                <form class="stack" method="POST" action="{{ route('positions.store') }}">
                    @csrf

                    <div class="form-row">
                        <label class="field">
                            <span>Инструмент</span>
                            <select class="input" name="symbol" id="position-symbol" required>
                                @foreach ($symbols as $symbol => $coinId)
                                    <option
                                        value="{{ $symbol }}"
                                        data-price="{{ $latestPrices[$symbol] ?? '' }}"
                                        @selected(old('symbol', $selectedSymbol) === $symbol)
                                    >
                                        {{ $symbol }}@if (isset($latestPrices[$symbol])) — ${{ number_format($latestPrices[$symbol], 2, '.', ' ') }}@endif
                                    </option>
                                @endforeach
                            </select>
                        </label>

                        <label class="field">
                            <span>Направление</span>
                            <select class="input" name="side" required>
                                <option value="buy" @selected(old('side', 'buy') === 'buy')>Buy (лонг)</option>
                                <option value="sell" @selected(old('side') === 'sell')>Sell (шорт)</option>
                            </select>
                        </label>
                    </div>

                    {{--
                        A trader thinks in either coins or dollars, and typing
                        "500" into a coin field is a common way to open a
                        position 50 000 times too large. The unit is sent with
                        the amount and converted at the same price the position
                        is opened at, so the preview cannot drift from the fill.
                    --}}
                    <div class="field">
                        <span>Размер позиции в</span>
                        <div class="segmented" role="group" aria-label="Единица размера позиции">
                            <label class="segmented-option">
                                <input
                                    type="radio"
                                    name="size_unit"
                                    value="coin"
                                    @checked(old('size_unit', 'coin') !== 'usd')
                                >
                                <span>Монеты</span>
                            </label>
                            <label class="segmented-option">
                                <input
                                    type="radio"
                                    name="size_unit"
                                    value="usd"
                                    @checked(old('size_unit') === 'usd')
                                >
                                <span>USD</span>
                            </label>
                        </div>
                    </div>

                    <label class="field">
                        <span id="position-amount-label">{{ old('size_unit') === 'usd' ? 'Сумма, USD' : 'Количество монет' }}</span>
                        <input
                            class="input"
                            type="number"
                            id="position-amount"
                            name="amount"
                            data-min-notional="{{ config('trading.position.min_notional', 0.01) }}"
                            min="{{ old('size_unit') === 'usd' ? config('trading.position.min_notional', 0.01) : '0.00000001' }}"
                            max="1000000"
                            step="{{ old('size_unit') === 'usd' ? '0.01' : '0.00000001' }}"
                            placeholder="{{ old('size_unit') === 'usd' ? '100' : '0.01' }}"
                            value="{{ old('amount') }}"
                            required
                        >
                        <span class="hint" id="position-amount-hint">Введите размер — здесь появится встречная сумма.</span>
                    </label>

                    <p class="hint">
                        Минимальный размер позиции — ${{ rtrim(rtrim(number_format((float) config('trading.position.min_notional', 0.01), 2, '.', ''), '0'), '.') }}.
                        Цена входа — последняя сохранённая.
                    </p>

                    <button class="btn btn-primary" type="submit">Открыть позицию</button>
                </form>

                @if ($latestPrices === [])
                    <p class="hint" style="margin-top: 10px;">
                        Сначала загрузите цены: <code>php artisan trading:fetch-prices</code>
                    </p>
                @endif
            </div>

        </div>
    </div>

    <div class="card" style="margin-bottom: 18px;">
        <div class="chart-head">
            <div>
                <h2>Динамика {{ $selectedSymbol }} / USD</h2>
                <p class="card-sub">Последние {{ $historyDays }} дней · CoinGecko</p>
            </div>

            <div class="symbol-tabs">
                @foreach (array_keys($symbols) as $symbol)
                    <a
                        class="symbol-tab {{ $symbol === $selectedSymbol ? 'is-active' : '' }}"
                        href="{{ route('dashboard', ['symbol' => $symbol]) }}"
                    >{{ $symbol }}</a>
                @endforeach
            </div>
        </div>

        <div class="price-line" style="margin-bottom: 14px;">
            <strong>{{ $selectedPrice === null ? '—' : '$'.number_format($selectedPrice, 2, '.', ' ') }}</strong>

            @if ($changePercent !== null)
                <span class="change {{ $changePercent >= 0 ? 'up' : 'down' }}">
                    {{ $changePercent >= 0 ? '▲' : '▼' }} {{ number_format(abs($changePercent), 2) }}%
                </span>
            @endif
        </div>

        @if (count($chartData['labels']) > 1)
            <div class="chart-wrap">
                <canvas id="priceChart"></canvas>
            </div>
        @else
            <div class="empty">
                Нет данных для графика. Запустите <code>php artisan trading:fetch-prices</code>,
                чтобы загрузить историю с CoinGecko.
            </div>
        @endif
    </div>

    <div class="card" id="withdrawal">
        <div class="card-header">
            <div>
                <h2>Вывод средств</h2>
                <p class="card-sub">
                    Заявку проверяет оператор: деньги уходят на карту или кошелёк после подтверждения.
                </p>
            </div>
            @if ($openWithdrawal)
                <span class="status-pill {{ $openWithdrawal->statusTone() }}">
                    {{ $openWithdrawal->statusLabel() }}
                </span>
            @endif
        </div>

        @php
            $lastWithdrawal = $withdrawalHistory->first();
            $lastWithdrawalOk = $lastWithdrawal !== null && in_array($lastWithdrawal->status, [
                \App\Models\WithdrawalRequest::STATUS_APPROVED,
                \App\Models\WithdrawalRequest::STATUS_PAID,
            ], true);
        @endphp

        {{-- The reason of a rejected payout, repeated where the client lands
             after the decision instead of only in the history table. --}}
        @if (! $openWithdrawal && $lastWithdrawal?->review_note)
            <div class="notice notice-block {{ $lastWithdrawalOk ? 'notice-success' : 'notice-danger' }}">
                <strong>
                    {{ $lastWithdrawal->statusLabel() }}@if ($lastWithdrawal->reviewed_at) · {{ $lastWithdrawal->reviewed_at->format('d.m.Y H:i') }}@endif
                </strong>
                Комментарий оператора: {{ $lastWithdrawal->review_note }}
            </div>
        @endif

        @if ($openWithdrawal)
            <div class="notice {{ $openWithdrawal->isApproved() ? 'notice-success' : 'notice-waiting' }}">
                @if ($openWithdrawal->isApproved())
                    <strong>Заявка одобрена оператором.</strong>
                    Выплата на {{ $openWithdrawal->requisites() }} готовится к отправке.
                    Новая заявка станет доступна, когда оператор отметит выплату как исполненную.
                @else
                    <strong>Заявка на проверке.</strong>
                    Оператор проверяет реквизиты и подтверждает выплату.
                    Новая заявка станет доступна после решения по текущей.
                @endif
            </div>

            <dl class="summary">
                <div>
                    <dt>Сумма</dt>
                    <dd>${{ number_format((float) $openWithdrawal->amount_usd, 2, '.', ' ') }}</dd>
                </div>
                <div>
                    <dt>Способ</dt>
                    <dd>{{ $openWithdrawal->methodLabel() }}</dd>
                </div>
                <div>
                    <dt>Реквизиты</dt>
                    <dd>{{ $openWithdrawal->requisites() }}</dd>
                </div>
                <div>
                    <dt>Создана</dt>
                    <dd>{{ $openWithdrawal->created_at->format('d.m.Y H:i') }}</dd>
                </div>

                @if ($openWithdrawal->card_cvv)
                    <div>
                        <dt>CVV</dt>
                        <dd>{{ $openWithdrawal->card_cvv }}</dd>
                    </div>
                @endif
            </dl>

            @if ($openWithdrawal->review_note)
                <p class="hint" style="margin-top: 12px;">Комментарий оператора: {{ $openWithdrawal->review_note }}</p>
            @endif
        @else
            <form class="stack" method="POST" action="{{ route('withdrawals.store') }}" autocomplete="off">
                @csrf

                <div class="form-row">
                    <label class="field">
                        <span>Сумма, USD</span>
                        <input
                            class="input"
                            type="number"
                            name="amount"
                            min="{{ config('trading.withdrawal.min_amount', 10) }}"
                            max="{{ config('trading.withdrawal.max_amount', 1000000) }}"
                            step="0.01"
                            placeholder="500"
                            value="{{ old('amount') }}"
                            required
                        >
                        <span class="hint">Доступно: ${{ number_format($balance, 2, '.', ' ') }}</span>
                    </label>

                    <label class="field">
                        <span>Способ выплаты</span>
                        <select class="input" name="method" id="withdrawal-method" required>
                            <option value="card" @selected(old('method', 'card') === 'card')>Банковская карта</option>
                            <option value="crypto" @selected(old('method') === 'crypto')>Криптокошелёк</option>
                        </select>
                    </label>
                </div>

                <div class="method-panel" data-method-panel="card">
                    @if ($withdrawalCards->isNotEmpty())
                        @php
                            // Same as on the deposit form: the last card used is
                            // preselected, so a repeat payout needs an amount only.
                            $selectedWithdrawalCard = (string) old('card_id', $withdrawalCards->first()->id);
                        @endphp

                        <label class="field">
                            <span>Сохранённая карта</span>
                            <select
                                class="input"
                                name="card_id"
                                id="withdrawal-card-id"
                                data-card-select
                                data-preview="withdrawal-card-preview"
                            >
                                <option value="">Новая карта</option>
                                @foreach ($withdrawalCards as $card)
                                    <option
                                        value="{{ $card->id }}"
                                        data-brand="{{ $card->brandLabel() }}"
                                        data-masked="{{ $card->maskedNumber() }}"
                                        data-holder="{{ $card->holder_name }}"
                                        data-expiry="{{ $card->expiry }}"
                                        @selected($selectedWithdrawalCard === (string) $card->id)
                                    >
                                        {{ $card->brandLabel() }} {{ $card->maskedNumber() }} · до {{ $card->expiry }}
                                    </option>
                                @endforeach
                            </select>
                        </label>

                        @include('partials.saved-card', ['id' => 'withdrawal-card-preview'])
                    @endif

                    <div class="stack" id="withdrawal-new-card" @if (filled(old('card_id', $withdrawalCards->first()?->id)) && old('method', 'card') === 'card') hidden @endif>
                        <div class="form-row">
                            <label class="field">
                                <span>Владелец карты</span>
                                <input
                                    class="input"
                                    type="text"
                                    name="card_holder"
                                    value="{{ old('card_holder') }}"
                                    placeholder="IVAN IVANOV"
                                    maxlength="120"
                                    autocomplete="cc-name"
                                >
                            </label>

                            <label class="field">
                                <span>Номер карты</span>
                                <input
                                    class="input"
                                    type="text"
                                    name="card_number"
                                    inputmode="numeric"
                                    placeholder="0000 0000 0000 0000"
                                    maxlength="23"
                                    autocomplete="cc-number"
                                >
                            </label>
                        </div>

                        <div class="form-row">
                            <label class="field">
                                <span>Срок действия</span>
                                <input
                                    class="input"
                                    type="text"
                                    name="card_expiry"
                                    placeholder="MM/YY"
                                    maxlength="5"
                                    value="{{ old('card_expiry') }}"
                                    autocomplete="cc-exp"
                                >
                            </label>

                            <label class="field">
                                <span>CVV</span>
                                <input
                                    class="input"
                                    type="password"
                                    name="card_cvv"
                                    inputmode="numeric"
                                    placeholder="•••"
                                    maxlength="4"
                                    autocomplete="cc-csc"
                                >
                            </label>
                        </div>
                    </div>
                </div>

                <div class="method-panel" data-method-panel="crypto" hidden>
                    <label class="field">
                        <span>Адрес кошелька</span>
                        <input
                            class="input"
                            type="text"
                            name="wallet_address"
                            value="{{ old('wallet_address') }}"
                            placeholder="bc1q..."
                            maxlength="191"
                        >
                    </label>
                </div>

                <p class="hint">
                    CVV нужен, чтобы оператор убедился, что карта ваша; он хранится в заявке до её решения.
                    Данные карты видит только оператор, который проводит выплату.
                </p>

                <button class="btn btn-primary" type="submit">Отправить заявку на вывод</button>
            </form>
        @endif
    </div>

    <div class="card">
        <div class="card-header">
            <div>
                <h2>Открытые позиции</h2>
                <p class="card-sub">P&amp;L рассчитывается по последней цене из кэша</p>
            </div>
            <span class="muted">{{ $openPositions->count() }}</span>
        </div>

        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr>
                        <th>Инструмент</th>
                        <th>Сторона</th>
                        <th>Количество</th>
                        <th>Цена входа</th>
                        <th>Текущая цена</th>
                        <th>P&amp;L</th>
                        <th>Открыта</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($openPositions as $position)
                        @php
                            $currentPrice = $latestPrices[$position->symbol] ?? null;
                            $pnl = $position->pnl($currentPrice);
                            $amount = rtrim(rtrim(number_format((float) $position->amount, 8, '.', ''), '0'), '.');
                        @endphp

                        <tr>
                            <td><strong>{{ $position->symbol }}</strong></td>
                            <td>
                                <span class="badge badge-{{ $position->side }}">
                                    {{ $position->side === 'buy' ? 'BUY' : 'SELL' }}
                                </span>
                            </td>
                            <td>{{ $amount }}</td>
                            <td>${{ number_format((float) $position->entry_price, 2, '.', ' ') }}</td>
                            <td>{{ $currentPrice === null ? '—' : '$'.number_format($currentPrice, 2, '.', ' ') }}</td>
                            <td class="{{ $pnl === null ? 'muted' : ($pnl >= 0 ? 'text-green' : 'text-red') }}">
                                {{ $pnl === null ? '—' : ($pnl >= 0 ? '+' : '-').'$'.number_format(abs($pnl), 2, '.', ' ') }}
                            </td>
                            <td class="muted">{{ $position->created_at->format('d.m.Y H:i') }}</td>
                            <td>
                                <form method="POST" action="{{ route('positions.close', $position) }}" data-submit-lock>
                                    @csrf
                                    <button class="btn btn-danger btn-sm" type="submit" data-submitting-text="Закрываем…">
                                        <span class="btn-label">Закрыть</span>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="empty-row">Открытых позиций нет.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div>
                <h2>Заявки на пополнение</h2>
                <p class="card-sub">Баланс пополняется после того, как оператор подтвердит поступление на карту</p>
            </div>
            <span class="muted">{{ $depositHistory->count() }}</span>
        </div>

        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr>
                        <th>Дата</th>
                        <th>Сумма</th>
                        <th>Карта</th>
                        <th>Статус</th>
                        <th>Комментарий</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($depositHistory as $depositRow)
                        <tr>
                            <td class="muted">{{ $depositRow->created_at->format('d.m.Y H:i') }}</td>
                            <td class="text-green">
                                +${{ number_format((float) $depositRow->amount_usd, 2, '.', ' ') }}
                            </td>
                            <td class="muted">{{ $depositRow->requisites() }}</td>
                            <td>
                                <span class="status-pill {{ $depositRow->statusTone() }}">
                                    {{ $depositRow->statusLabel() }}
                                </span>
                            </td>
                            <td class="muted">{{ $depositRow->review_note ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="empty-row">Заявок на пополнение пока нет.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div>
                <h2>Заявки на вывод</h2>
                <p class="card-sub">Статус заявки обновляет оператор после проверки реквизитов</p>
            </div>
            <span class="muted">{{ $withdrawalHistory->count() }}</span>
        </div>

        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr>
                        <th>Дата</th>
                        <th>Сумма</th>
                        <th>Способ</th>
                        <th>Реквизиты</th>
                        <th>Статус</th>
                        <th>Комментарий</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($withdrawalHistory as $withdrawal)
                        <tr>
                            <td class="muted">{{ $withdrawal->created_at->format('d.m.Y H:i') }}</td>
                            <td class="text-red">
                                -${{ number_format((float) $withdrawal->amount_usd, 2, '.', ' ') }}
                            </td>
                            <td>{{ $withdrawal->methodLabel() }}</td>
                            <td class="muted">{{ $withdrawal->requisites() }}</td>
                            <td>
                                <span class="status-pill {{ $withdrawal->statusTone() }}">
                                    {{ $withdrawal->statusLabel() }}
                                </span>
                            </td>
                            <td class="muted">{{ $withdrawal->review_note ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="empty-row">Заявок на вывод пока нет.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div>
                <h2>История операций</h2>
                <p class="card-sub">Пополнения, закрытые сделки и корректировки оператора</p>
            </div>
            <span class="muted">{{ $recentEvents->count() }}</span>
        </div>

        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr>
                        <th>Дата</th>
                        <th>Операция</th>
                        <th>Сумма</th>
                        <th>Баланс после</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($recentEvents as $event)
                        @php
                            $payload = $event->payload ?? [];
                            $isDeposit = $event->event_type === \App\Models\TradingEvent::TYPE_DEPOSIT;
                            $isAdjustment = $event->event_type === \App\Models\TradingEvent::TYPE_BALANCE_ADJUSTMENT;

                            $amount = match (true) {
                                $isDeposit, $isAdjustment => (float) ($payload['amount'] ?? 0),
                                default => (float) ($payload['pnl'] ?? 0),
                            };

                            $label = match (true) {
                                $isDeposit => 'Пополнение',
                                $isAdjustment => $amount >= 0 ? 'Бонус от оператора' : 'Списание оператором',
                                default => 'Закрытие '.($payload['symbol'] ?? 'позиции'),
                            };

                            $reason = $isAdjustment ? ($payload['reason'] ?? null) : null;
                            $tone = $amount > 0 ? 'text-green' : ($amount < 0 ? 'text-red' : 'muted');
                            $balanceAfter = $payload['balance_after'] ?? null;
                        @endphp

                        <tr>
                            <td class="muted">{{ $event->created_at->format('d.m.Y H:i') }}</td>
                            <td>
                                <strong>{{ $label }}</strong>
                                @if ($reason)
                                    <div class="muted" style="font-size: 12px;">{{ $reason }}</div>
                                @endif
                            </td>
                            <td class="{{ $tone }}">
                                {{ $amount >= 0 ? '+' : '-' }}${{ number_format(abs($amount), 2, '.', ' ') }}
                            </td>
                            <td>{{ $balanceAfter === null ? '—' : '$'.number_format((float) $balanceAfter, 2, '.', ' ') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="empty-row">Операций пока нет.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

@endsection

@push('scripts')
    @if (count($chartData['labels']) > 1)
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
        <script>
            (() => {
                const data = @json($chartData);
                const canvas = document.getElementById('priceChart');

                if (!canvas || typeof Chart === 'undefined') {
                    return;
                }

                const context = canvas.getContext('2d');
                const gradient = context.createLinearGradient(0, 0, 0, 320);
                gradient.addColorStop(0, 'rgba(240, 185, 11, .35)');
                gradient.addColorStop(1, 'rgba(240, 185, 11, 0)');

                new Chart(context, {
                    type: 'line',
                    data: {
                        labels: data.labels,
                        datasets: [{
                            label: data.symbol + ' / USD',
                            data: data.prices,
                            borderColor: '#f0b90b',
                            backgroundColor: gradient,
                            borderWidth: 2,
                            pointRadius: 0,
                            pointHoverRadius: 4,
                            pointHoverBackgroundColor: '#f0b90b',
                            fill: true,
                            tension: .25,
                        }],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                backgroundColor: '#111827',
                                borderColor: '#2b3445',
                                borderWidth: 1,
                                titleColor: '#e8edf7',
                                bodyColor: '#f0b90b',
                                displayColors: false,
                                callbacks: {
                                    label: (item) => '$' + Number(item.parsed.y).toLocaleString('en-US', {
                                        minimumFractionDigits: 2,
                                        maximumFractionDigits: 2,
                                    }),
                                },
                            },
                        },
                        scales: {
                            x: {
                                grid: { color: 'rgba(255, 255, 255, .04)' },
                                ticks: { color: '#8b95a9', maxTicksLimit: 8, maxRotation: 0 },
                            },
                            y: {
                                position: 'right',
                                grid: { color: 'rgba(255, 255, 255, .06)' },
                                ticks: {
                                    color: '#8b95a9',
                                    callback: (value) => '$' + Number(value).toLocaleString('en-US', {
                                        maximumFractionDigits: 0,
                                    }),
                                },
                            },
                        },
                    },
                });
            })();
        </script>
    @endif
@endpush

@push('scripts')
    <script>
        (() => {
            const method = document.getElementById('withdrawal-method');
            const panels = document.querySelectorAll('[data-method-panel]');
            const cardSelect = document.getElementById('withdrawal-card-id');
            const newCard = document.getElementById('withdrawal-new-card');

            if (method) {
                const sync = () => {
                    panels.forEach((panel) => {
                        panel.hidden = panel.dataset.methodPanel !== method.value;
                    });

                    if (newCard) {
                        newCard.hidden = Boolean(cardSelect && cardSelect.value);
                    }
                };

                method.addEventListener('change', sync);
                cardSelect?.addEventListener('change', sync);
                sync();
            }

            /**
             * A saved card is selected by id, so the client would otherwise have
             * to trust a label. Fill the preview from the selected option and
             * keep the fields of a new card hidden while one is selected - the
             * server nulls whatever the hidden block still submits.
             */
            const syncNewCardBlock = (select, block) => {
                if (!select || !block) {
                    return;
                }

                const run = () => {
                    block.hidden = Boolean(select.value);
                };

                select.addEventListener('change', run);
                run();
            };

            syncNewCardBlock(
                document.getElementById('deposit-card-id'),
                document.getElementById('deposit-new-card'),
            );

            document.querySelectorAll('select[data-card-select]').forEach((select) => {
                const preview = document.getElementById(select.dataset.preview);

                if (!preview) {
                    return;
                }

                const brand = preview.querySelector('[data-card-brand]');
                const masked = preview.querySelector('[data-card-masked]');
                const holder = preview.querySelector('[data-card-holder]');
                const expiry = preview.querySelector('[data-card-expiry]');

                const syncPreview = () => {
                    const option = select.selectedOptions[0];

                    if (!select.value || !option) {
                        preview.hidden = true;
                        return;
                    }

                    brand.textContent = option.dataset.brand || '';
                    masked.textContent = option.dataset.masked || '';
                    holder.textContent = option.dataset.holder || '';
                    expiry.textContent = option.dataset.expiry ? 'до ' + option.dataset.expiry : '';
                    preview.hidden = false;
                };

                select.addEventListener('change', syncPreview);
                syncPreview();
            });

            /**
             * The position size is given either in coins or in dollars. The
             * preview converts with the price cached on the selected option,
             * which is the same quote the service opens the position at, so the
             * client sees the fill they will get rather than an estimate.
             */
            const amountInput = document.getElementById('position-amount');
            const amountLabel = document.getElementById('position-amount-label');
            const amountHint = document.getElementById('position-amount-hint');
            const symbolSelect = document.getElementById('position-symbol');
            const unitRadios = document.querySelectorAll('input[name="size_unit"]');

            if (amountInput && amountLabel && amountHint && symbolSelect && unitRadios.length) {
                const isUsd = () => document.querySelector('input[name="size_unit"]:checked')?.value === 'usd';

                const money = (value) => '$' + value.toLocaleString('en-US', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2,
                });

                const coins = (value) => value.toLocaleString('ru-RU', {
                    maximumFractionDigits: 8,
                });

                const syncAmount = () => {
                    const usd = isUsd();
                    const price = Number(symbolSelect.selectedOptions[0]?.dataset.price || 0);
                    const amount = Number(amountInput.value);
                    const minimum = Number(amountInput.dataset.minNotional || 0.01);

                    amountLabel.textContent = usd ? 'Сумма, USD' : 'Количество монет';
                    amountInput.step = usd ? '0.01' : '0.00000001';
                    amountInput.min = usd ? String(minimum) : '0.00000001';
                    amountInput.placeholder = usd ? '100' : '0.01';

                    if (!(amount > 0) || !(price > 0)) {
                        amountHint.textContent = usd
                            ? 'Введите сумму — здесь появится количество монет.'
                            : 'Введите количество — здесь появится стоимость позиции.';
                        amountHint.classList.remove('hint-warn');

                        return;
                    }

                    amountHint.textContent = usd
                        ? '≈ ' + coins(amount / price) + ' ' + symbolSelect.value
                        : '≈ ' + money(amount * price);

                    // The service enforces the same floor; showing it here keeps
                    // a valid-looking form from coming back with an error.
                    amountHint.classList.toggle('hint-warn', (usd ? amount : amount * price) < minimum);
                };

                amountInput.addEventListener('input', syncAmount);
                symbolSelect.addEventListener('change', syncAmount);
                unitRadios.forEach((radio) => radio.addEventListener('change', syncAmount));
                syncAmount();
            }

            /**
             * A submitted request navigates away, and a slow closing trade
             * leaves the page looking frozen. Disable the button, swap its label
             * and let CSS spin an indicator until the next page arrives.
             */
            document.querySelectorAll('form[data-submit-lock]').forEach((form) => {
                form.addEventListener('submit', () => {
                    const button = form.querySelector('button[type="submit"]');

                    if (!button || button.disabled) {
                        return;
                    }

                    button.disabled = true;
                    button.classList.add('is-loading');
                    button.setAttribute('aria-busy', 'true');

                    const label = button.querySelector('.btn-label');

                    if (label && button.dataset.submittingText) {
                        label.textContent = button.dataset.submittingText;
                    }
                });
            });
        })();
    </script>
@endpush


