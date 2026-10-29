<?php

namespace App\Services;

use App\Exceptions\TradingException;
use App\Models\PriceHistory;
use App\Models\TradingAccount;
use App\Models\TradingEvent;
use App\Models\TradingPosition;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Virtual trading operations.
 *
 * Model: 1x collateral, no leverage. Opening a position blocks its notional
 * value on the account; closing returns the collateral plus/minus the P&L.
 */
class TradingService
{
    /**
     * Units the client may size a position in: a number of coins or a USD
     * value that is converted with the price the trade is opened at.
     */
    public const SIZE_UNIT_COIN = 'coin';

    public const SIZE_UNIT_USD = 'usd';

    public function accountFor(User $user): TradingAccount
    {
        return TradingAccount::query()->firstOrCreate(
            ['user_id' => $user->id],
            ['balance_usd' => (float) config('trading.initial_balance', 0)],
        );
    }

    public function latestPrice(string $symbol): ?float
    {
        $price = PriceHistory::query()
            ->where('symbol', $symbol)
            ->orderByDesc('recorded_at')
            ->value('price');

        return $price === null ? null : (float) $price;
    }

    /**
     * @return array<string, float> prices keyed by symbol
     */
    public function latestPrices(array $symbols): array
    {
        if ($symbols === []) {
            return [];
        }

        $latestIds = PriceHistory::query()
            ->selectRaw('MAX(id) as id')
            ->whereIn('symbol', $symbols)
            ->groupBy('symbol');

        return PriceHistory::query()
            ->whereIn('id', $latestIds)
            ->pluck('price', 'symbol')
            ->map(fn ($price) => (float) $price)
            ->all();
    }

    /**
     * Credit the account, as a deposit approval asks for.
     *
     * Kept as a thin alias so existing callers keep the old signature; the
     * interesting work happens in `creditDeposit()`, which can also report the
     * balances and whether the credit was already applied.
     */
    public function deposit(User $user, float $amount): TradingAccount
    {
        return $this->creditDeposit($user, $amount)['account'];
    }

    /**
     * Credit a deposit and publish it to the feed Krayin CRM reads.
     *
     * `$reference` is the idempotency key: the CRM sends the deposit request id
     * when an operator approves it, so a bridge retry whose first response was
     * lost cannot credit the same request twice. The reference is stored in the
     * event payload, which is what the caller of `adjustBalance()` relies on as
     * well.
     *
     * @return array{account: TradingAccount, event: TradingEvent|null, balance_before: float, balance_after: float, applied: bool}
     */
    public function creditDeposit(User $user, float $amount, ?string $reference = null): array
    {
        if ($amount <= 0) {
            throw new TradingException('Сумма пополнения должна быть больше нуля.');
        }

        $this->accountFor($user);

        return DB::transaction(function () use ($user, $amount, $reference) {
            $account = $this->lockAccount($user);

            if ($reference !== null) {
                $existing = $this->findDepositEvent($user, $reference);

                if ($existing !== null) {
                    $payload = is_array($existing->payload) ? $existing->payload : [];

                    return [
                        'account' => $account,
                        'event' => $existing,
                        'balance_before' => (float) ($payload['balance_before'] ?? $account->balance_usd),
                        'balance_after' => (float) ($payload['balance_after'] ?? $account->balance_usd),
                        'applied' => false,
                    ];
                }
            }

            $balanceBefore = (float) $account->balance_usd;

            $account->balance_usd = round($balanceBefore + $amount, 8);
            $account->save();

            $payload = [
                'amount' => round($amount, 8),
                'balance_before' => $balanceBefore,
                'balance_after' => (float) $account->balance_usd,
            ];

            if ($reference !== null) {
                $payload['reference'] = $reference;
            }

            $event = $this->recordEvent($user, TradingEvent::TYPE_DEPOSIT, $payload);

            return [
                'account' => $account,
                'event' => $event,
                'balance_before' => $balanceBefore,
                'balance_after' => (float) $account->balance_usd,
                'applied' => true,
            ];
        });
    }

    /**
     * The deposit event written for an earlier delivery of the same reference.
     */
    protected function findDepositEvent(User $user, string $reference): ?TradingEvent
    {
        return TradingEvent::query()
            ->where('user_id', $user->id)
            ->where('event_type', TradingEvent::TYPE_DEPOSIT)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.reference')) = ?", [$reference])
            ->first();
    }

    /**
     * Change a balance by hand, as requested by a CRM operator.
     *
     * The amount is stated in USD and is not converted from crypto: a manual
     * adjustment is a bookkeeping entry, not a trade. The balance may not go
     * below zero, and the CRM adjustment id makes the call idempotent, so an
     * HTTP retry cannot credit the same bonus twice.
     *
     * @return array{account: TradingAccount, event: TradingEvent, balance_before: float, balance_after: float, applied: bool}
     */
    public function adjustBalance(
        User $user,
        float $amount,
        string $reason,
        ?int $adjustmentId = null,
        ?string $operatorName = null,
    ): array {
        $amount = round($amount, 2);
        $reason = trim($reason);

        if ($amount == 0.0) {
            throw new TradingException('Сумма корректировки не может быть нулевой.');
        }

        if ($reason === '') {
            throw new TradingException('Укажите причину корректировки.');
        }

        $max = (float) config('trading.bridge.max_amount', 1000000);

        if ($max > 0 && abs($amount) > $max) {
            throw new TradingException(
                'Сумма корректировки не может превышать $'.number_format($max, 0, '.', ' ').'.'
            );
        }

        $this->accountFor($user);

        return DB::transaction(function () use ($user, $amount, $reason, $adjustmentId, $operatorName) {
            if ($adjustmentId !== null) {
                $existing = $this->findAdjustmentEvent($adjustmentId);

                if ($existing) {
                    $payload = $existing->payload ?? [];

                    return [
                        'account' => $this->accountFor($user),
                        'event' => $existing,
                        'balance_before' => (float) ($payload['balance_before'] ?? 0),
                        'balance_after' => (float) ($payload['balance_after'] ?? 0),
                        'applied' => false,
                    ];
                }
            }

            $account = $this->lockAccount($user);
            $balanceBefore = (float) $account->balance_usd;
            $balanceAfter = round($balanceBefore + $amount, 8);

            if ($balanceAfter < 0) {
                throw new TradingException(
                    'Недостаточно средств: баланс $'.number_format($balanceBefore, 2, '.', ' ').
                    ', списание $'.number_format(abs($amount), 2, '.', ' ').'.'
                );
            }

            $account->balance_usd = $balanceAfter;
            $account->save();

            $event = $this->recordEvent($user, TradingEvent::TYPE_BALANCE_ADJUSTMENT, [
                'reference' => $adjustmentId === null ? null : (string) $adjustmentId,
                'amount' => $amount,
                'reason' => $reason,
                'operator_name' => $operatorName,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
            ]);

            return [
                'account' => $account,
                'event' => $event,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'applied' => true,
            ];
        }, 3);
    }

    /**
     * The event written for an earlier delivery of the same CRM adjustment.
     */
    protected function findAdjustmentEvent(int $adjustmentId): ?TradingEvent
    {
        return TradingEvent::query()
            ->where('event_type', TradingEvent::TYPE_BALANCE_ADJUSTMENT)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '\$.reference')) = ?", [(string) $adjustmentId])
            ->first();
    }

    public function openPosition(
        User $user,
        string $symbol,
        string $side,
        float $amount,
        string $sizeUnit = self::SIZE_UNIT_COIN,
    ): TradingPosition {
        $symbol = strtoupper($symbol);

        if (! array_key_exists($symbol, (array) config('trading.symbols', []))) {
            throw new TradingException('Инструмент «'.$symbol.'» не поддерживается.');
        }

        if (! in_array($side, [TradingPosition::SIDE_BUY, TradingPosition::SIDE_SELL], true)) {
            throw new TradingException('Неизвестная сторона позиции.');
        }

        if (! in_array($sizeUnit, [self::SIZE_UNIT_COIN, self::SIZE_UNIT_USD], true)) {
            throw new TradingException('Размер позиции указывается в монетах или в долларах.');
        }

        if ($amount <= 0) {
            throw new TradingException('Размер позиции должен быть больше нуля.');
        }

        $requestedAmount = $amount;

        $price = $this->latestPrice($symbol);

        if ($price === null) {
            throw new TradingException(
                'Нет сохранённой цены для '.$symbol.'. Запустите: php artisan trading:fetch-prices'
            );
        }

        if ($sizeUnit === self::SIZE_UNIT_USD) {
            // Convert with the same price the notional is computed from, so the
            // client's "$100" is exactly what leaves the balance - no re-quote.
            $amount = round($amount / $price, 8);
        }

        if ($amount <= 0) {
            throw new TradingException('Сумма в долларах слишком мала для этого инструмента.');
        }

        $notional = round($price * $amount, 8);
        $minNotional = (float) config('trading.position.min_notional', 0.01);

        if ($notional < $minNotional) {
            throw new TradingException(
                'Минимальный размер позиции — '.rtrim(rtrim(number_format($minNotional, 2, '.', ''), '0'), '.').' USD.'
            );
        }

        $this->accountFor($user);

        return DB::transaction(function () use ($user, $symbol, $side, $amount, $sizeUnit, $requestedAmount, $price, $notional) {
            $account = $this->lockAccount($user);
            $balance = (float) $account->balance_usd;

            if ($balance + 1e-9 < $notional) {
                throw new TradingException(
                    'Недостаточно средств: нужно '.number_format($notional, 2).
                    ' USD, доступно '.number_format($balance, 2).' USD.'
                );
            }

            $account->balance_usd = round($balance - $notional, 8);
            $account->save();

            $position = $user->tradingPositions()->create([
                'symbol' => $symbol,
                'side' => $side,
                'amount' => $amount,
                'entry_price' => $price,
                'status' => TradingPosition::STATUS_OPEN,
            ]);

            $this->recordEvent($user, TradingEvent::TYPE_POSITION_OPENED, [
                'position_id' => $position->id,
                'symbol' => $symbol,
                'side' => $side,
                'amount' => round($amount, 8),
                'size_unit' => $sizeUnit,
                'requested_amount' => round($requestedAmount, 8),
                'entry_price' => $price,
                'notional' => $notional,
                'balance_after' => (float) $account->balance_usd,
            ]);

            return $position;
        });
    }

    public function closePosition(TradingPosition $position): TradingPosition
    {
        if (! $position->isOpen()) {
            throw new TradingException('Позиция уже закрыта.');
        }

        $price = $this->latestPrice($position->symbol);

        if ($price === null) {
            throw new TradingException(
                'Нет сохранённой цены для '.$position->symbol.'. Запустите: php artisan trading:fetch-prices'
            );
        }

        $this->accountFor($position->user);

        return DB::transaction(function () use ($position, $price) {
            $locked = TradingPosition::query()
                ->whereKey($position->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->isOpen()) {
                throw new TradingException('Позиция уже закрыта.');
            }

            $account = $this->lockAccount($locked->user);
            $pnl = $locked->pnl($price) ?? 0.0;

            // Return the collateral and apply the result. The balance never
            // goes below zero: a losing short is "liquidated" at zero.
            $account->balance_usd = round(
                max(0.0, (float) $account->balance_usd + $locked->notional() + $pnl),
                8,
            );
            $account->save();

            $locked->forceFill([
                'status' => TradingPosition::STATUS_CLOSED,
                'close_price' => $price,
                'closed_at' => now(),
            ])->save();

            $this->recordEvent($locked->user, TradingEvent::TYPE_POSITION_CLOSED, [
                'position_id' => $locked->id,
                'symbol' => $locked->symbol,
                'side' => $locked->side,
                'amount' => round((float) $locked->amount, 8),
                'entry_price' => (float) $locked->entry_price,
                'close_price' => $price,
                'notional' => $locked->notional(),
                'pnl' => $pnl,
                'balance_after' => (float) $account->balance_usd,
            ]);

            return $locked;
        });
    }

    /**
     * Publish an action to the feed Krayin CRM reads from the shared database.
     *
     * The call is made inside the caller's transaction: if the trading action
     * is rolled back, the event disappears with it, so the CRM never sees a
     * deposit or a trade that never happened.
     *
     * @param  array<string, mixed>  $payload
     */
    private function recordEvent(User $user, string $eventType, array $payload): TradingEvent
    {
        return TradingEvent::query()->create([
            'user_id' => $user->id,
            'event_type' => $eventType,
            'payload' => $payload,
        ]);
    }

    /**
     * The balance row of the trader, locked for the current transaction.
     *
     * Public because the staging services (`DepositService`, `WithdrawalService`)
     * need the same row lock to serialise two tabs submitting at once.
     */
    public function lockAccount(User $user): TradingAccount
    {
        return TradingAccount::query()
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
