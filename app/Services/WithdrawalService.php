<?php

namespace App\Services;

use App\Exceptions\TradingException;
use App\Models\TradingAccount;
use App\Models\TradingEvent;
use App\Models\User;
use App\Models\WithdrawalCard;
use App\Models\WithdrawalRequest;
use App\Support\CardCipher;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Staging of withdrawal requests and the decision on them.
 *
 * Staging moves no money: a request is only recorded, together with the card
 * the client wants to be paid to, so the client cannot queue a second request
 * while the first one is open. The balance itself is debited once, when the
 * CRM confirms that the payout has actually been made (`approved -> paid`),
 * under the same locks that protect the request row.
 */
class WithdrawalService
{
    public function __construct(private readonly TradingService $trading) {}

    /**
     * The request the client is waiting on, if any.
     */
    public function openRequestFor(User $user): ?WithdrawalRequest
    {
        return WithdrawalRequest::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [WithdrawalRequest::STATUS_PENDING, WithdrawalRequest::STATUS_APPROVED])
            ->latest('id')
            ->with('card')
            ->first();
    }

    /**
     * Cards the client already saved, newest first.
     *
     * A card that has not been used for `trading.cards.retention_days` is
     * hidden from the form (0 keeps every card): the row, and every request
     * that points at it, stay in place, so the payout history is intact.
     *
     * @return Collection<int, WithdrawalCard>
     */
    public function cardsFor(User $user): Collection
    {
        $retentionDays = (int) config('trading.cards.retention_days', 0);

        return WithdrawalCard::query()
            ->where('user_id', $user->id)
            ->when(
                $retentionDays > 0,
                fn ($query) => $query->where('updated_at', '>=', now()->subDays($retentionDays)),
            )
            ->latest('id')
            ->get();
    }

    /**
     * @return Collection<int, WithdrawalRequest>
     */
    public function historyFor(User $user, int $limit = 10): Collection
    {
        return WithdrawalRequest::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->with('card')
            ->limit($limit)
            ->get();
    }

    /**
     * Record a withdrawal request and the card it should be paid to.
     *
     * @param  array<string, mixed>  $data  validated payload of StoreWithdrawalRequest
     */
    public function request(User $user, array $data): WithdrawalRequest
    {
        $amount = round((float) ($data['amount'] ?? 0), 8);
        $method = (string) ($data['method'] ?? WithdrawalRequest::METHOD_CARD);
        $cvv = $method === WithdrawalRequest::METHOD_CARD ? $this->cvv($data) : null;

        if ($amount <= 0) {
            throw new TradingException('Сумма вывода должна быть больше нуля.');
        }

        // Make sure the account row exists before it is locked.
        $this->trading->accountFor($user);

        return DB::transaction(function () use ($user, $data, $amount, $method, $cvv) {
            // Locking the balance row serialises two tabs submitting at once,
            // so the "one open request" rule cannot be raced.
            $account = TradingAccount::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->firstOrFail();

            $open = WithdrawalRequest::query()
                ->where('user_id', $user->id)
                ->whereIn('status', [WithdrawalRequest::STATUS_PENDING, WithdrawalRequest::STATUS_APPROVED])
                ->first();

            if ($open !== null) {
                throw new TradingException(
                    'Предыдущая заявка на вывод ещё не обработана. Дождитесь решения оператора.'
                );
            }

            if ($amount > (float) $account->balance_usd) {
                throw new TradingException('Недостаточно средств: сумма вывода больше доступного баланса.');
            }

            $card = $method === WithdrawalRequest::METHOD_CARD
                ? $this->resolveCard($user, $data)
                : null;

            $wallet = $method === WithdrawalRequest::METHOD_CRYPTO
                ? trim((string) ($data['wallet_address'] ?? ''))
                : null;

            $request = WithdrawalRequest::query()->create([
                'user_id' => $user->id,
                'withdrawal_card_id' => $card?->id,
                'amount_usd' => $amount,
                'method' => $method,
                'wallet_address' => $wallet,
                'card_cvv' => $cvv,
                'status' => WithdrawalRequest::STATUS_PENDING,
                'is_approved' => false,
            ]);

            // The payload is a public contract with the CRM: it carries the
            // last four digits only, never the number and never the CVV. The
            // code lives on the request row and is read on the payout screen.
            $this->recordEvent($user, TradingEvent::TYPE_WITHDRAWAL_REQUESTED, [
                'request_id' => $request->id,
                'amount' => $amount,
                'method' => $method,
                'status' => WithdrawalRequest::STATUS_PENDING,
                'card_last4' => $card?->last4,
                'card_brand' => $card?->brandLabel(),
                'wallet_address' => $wallet,
                'balance' => (float) $account->balance_usd,
            ]);

            return $request->load('card');
        });
    }

    /**
     * Record the operator's decision on a payout.
     *
     * `pending -> approved|rejected` only moves the request. The last step,
     * `approved -> paid`, is the CRM confirming that the money has actually
     * been sent: that is where the amount leaves the trader's balance, and
     * what the cabinet reads as «Выплачено». Both edits happen in one
     * transaction under the request lock, so a payout cannot be marked paid
     * without its debit and cannot be debited twice by a retried call — a
     * replay of the same decision answers with `applied = false` before any
     * money is touched.
     *
     * @return array{
     *     request: WithdrawalRequest,
     *     applied: bool,
     *     balance_before: float|null,
     *     balance_after: float|null
     * }
     */
    public function review(
        int $requestId,
        string $status,
        ?string $note = null,
        ?int $reviewerId = null,
    ): array {
        if (! in_array($status, [
            WithdrawalRequest::STATUS_APPROVED,
            WithdrawalRequest::STATUS_REJECTED,
            WithdrawalRequest::STATUS_PAID,
        ], true)) {
            throw new TradingException('Решение по заявке может быть только «одобрено», «отклонено» или «выплачено».');
        }

        return DB::transaction(function () use ($requestId, $status, $note, $reviewerId) {
            $request = WithdrawalRequest::query()
                ->lockForUpdate()
                ->find($requestId);

            if ($request === null) {
                throw new TradingException('Заявка на вывод не найдена.');
            }

            if ($request->status === $status) {
                return [
                    'request' => $request,
                    'applied' => false,
                    'balance_before' => null,
                    'balance_after' => null,
                ];
            }

            /**
             * `paid` closes an approved payout, and nothing else: a pending
             * request has not been approved yet, and a rejected one is closed
             * for good. The approval itself stays on record - its reviewer,
             * time and note are not overwritten by the completion.
             */
            $allowed = $request->isPending()
                ? in_array($status, [WithdrawalRequest::STATUS_APPROVED, WithdrawalRequest::STATUS_REJECTED], true)
                : $request->isApproved() && $status === WithdrawalRequest::STATUS_PAID;

            if (! $allowed) {
                throw new TradingException('Заявка уже обработана: '.$request->statusLabel().'.');
            }

            $attributes = [
                'status' => $status,
                'is_approved' => in_array($status, [
                    WithdrawalRequest::STATUS_APPROVED,
                    WithdrawalRequest::STATUS_PAID,
                ], true),
            ];

            if ($status !== WithdrawalRequest::STATUS_PAID) {
                $attributes['reviewed_by'] = $reviewerId;
                $attributes['reviewed_at'] = now();
                $attributes['review_note'] = $note !== null && trim($note) !== '' ? trim($note) : null;
            }

            /**
             * The money leaves the account only here. The status is still
             * `approved` at this point: a retry waits for the row lock, sees
             * `paid` and answers `applied = false` above, so the debit can
             * never run twice for the same request.
             */
            $balance = $status === WithdrawalRequest::STATUS_PAID
                ? $this->debit($request)
                : null;

            $request->forceFill($attributes)->save();

            return [
                'request' => $request,
                'applied' => true,
                'balance_before' => $balance['balance_before'] ?? null,
                'balance_after' => $balance['balance_after'] ?? null,
            ];
        });
    }

    /**
     * Take a completed payout out of the trader's balance.
     *
     * The request row is already locked by the caller and its status is still
     * `approved`, so the debit is bound to the single `approved -> paid`
     * transition. The account row is locked as well, because a trade, a
     * deposit or a manual adjustment may be changing the same balance at the
     * same moment.
     *
     * The balance may not go below zero: a payout approved while the money was
     * there can become unpayable after a losing trade. Paying it anyway would
     * silently create a debt, so the refusal goes back to the CRM and the
     * request stays `approved` for a retry once the account is topped up. The
     * transaction rolls the status back together with the debit.
     *
     * @return array{balance_before: float, balance_after: float}
     */
    private function debit(WithdrawalRequest $request): array
    {
        $user = $request->user;

        if ($user === null) {
            throw new TradingException('Клиент заявки на вывод не найден.');
        }

        // The account exists since a request cannot be staged without one, but
        // a request seeded into an empty installation must not fail here.
        $this->trading->accountFor($user);

        $account = $this->trading->lockAccount($user);
        $amount = round((float) $request->amount_usd, 8);
        $balanceBefore = (float) $account->balance_usd;

        if ($amount > $balanceBefore) {
            throw new TradingException(
                'Недостаточно средств: сумма вывода $'.number_format($amount, 2, '.', ' ').
                ' больше доступного баланса $'.number_format($balanceBefore, 2, '.', ' ').'.'
            );
        }

        $account->balance_usd = round($balanceBefore - $amount, 8);
        $account->save();

        return [
            'balance_before' => $balanceBefore,
            'balance_after' => (float) $account->balance_usd,
        ];
    }

    /**
     * The code the client typed for this payout, if any.
     *
     * A crypto payout has no CVV, and a payout to a saved card carries none
     * either: the form request nulls the field for both, this is the second
     * guard before the code reaches the request row.
     *
     * @param  array<string, mixed>  $data
     */
    private function cvv(array $data): ?string
    {
        $cvv = trim((string) ($data['card_cvv'] ?? ''));

        return $cvv === '' ? null : $cvv;
    }

    /**
     * Reuse the client's card when the number is already known, otherwise
     * store it. The CVV does not describe the card: it is written to the
     * request row by `request()` and only there, so a saved card never keeps a
     * code the client typed for another payout.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveCard(User $user, array $data): WithdrawalCard
    {
        $cardId = $data['card_id'] ?? null;

        if ($cardId !== null && $cardId !== '') {
            $card = WithdrawalCard::query()
                ->where('user_id', $user->id)
                ->whereKey((int) $cardId)
                ->first();

            // A card of another client must not be usable, and must not leak
            // its existence either: the message is the same as for a typo.
            if ($card === null) {
                throw new TradingException('Карта не найдена. Введите реквизиты заново.');
            }

            // Retention counts days since the last use, so a card picked from
            // the list must look fresh even though only its id was submitted.
            if ((int) config('trading.cards.retention_days', 0) > 0) {
                $card->touch();
            }

            return $card;
        }

        $digits = CardCipher::normalize((string) ($data['card_number'] ?? ''));
        $holder = trim((string) ($data['card_holder'] ?? ''));
        $expiry = CardCipher::normalizeExpiry((string) ($data['card_expiry'] ?? ''));

        if (! CardCipher::passesLuhn($digits)) {
            throw new TradingException('Проверьте номер карты: он не проходит проверку контрольной суммы.');
        }

        if ($holder === '') {
            throw new TradingException('Укажите имя владельца карты.');
        }

        if (CardCipher::isExpired($expiry)) {
            throw new TradingException('Срок действия карты истёк.');
        }

        $fingerprint = CardCipher::fingerprint($digits);

        $card = WithdrawalCard::query()
            ->where('user_id', $user->id)
            ->where('fingerprint', $fingerprint)
            ->first();

        if ($card !== null) {
            // Same card, possibly re-issued or with a corrected holder name.
            $card->fill([
                'holder_name' => $holder,
                'expiry' => $expiry,
                'brand' => CardCipher::brand($digits),
                'last4' => substr($digits, -4),
            ])->save();

            return $card;
        }

        return WithdrawalCard::query()->create([
            'user_id' => $user->id,
            'holder_name' => $holder,
            'brand' => CardCipher::brand($digits),
            'last4' => substr($digits, -4),
            'expiry' => $expiry,
            'pan_encrypted' => CardCipher::encrypt($digits),
            'fingerprint' => $fingerprint,
        ]);
    }

    /**
     * Publish the request to the feed Krayin CRM reads from the same database.
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
}
