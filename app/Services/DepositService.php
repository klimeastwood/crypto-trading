<?php

namespace App\Services;

use App\Exceptions\TradingException;
use App\Models\DepositCard;
use App\Models\DepositRequest;
use App\Models\TradingEvent;
use App\Models\User;
use App\Support\CardCipher;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Staging of deposit requests.
 *
 * Nothing in this service moves money. A deposit used to be credited the moment
 * the client typed an amount, which made the balance a number the client could
 * invent; now the client names the card the money is coming from and an
 * operator approves the request in the CRM. Until then the balance is untouched
 * and the client cannot queue a second request.
 */
class DepositService
{
    public function __construct(private readonly TradingService $trading) {}

    /**
     * The request the client is waiting on, if any.
     */
    public function openRequestFor(User $user): ?DepositRequest
    {
        return DepositRequest::query()
            ->where('user_id', $user->id)
            ->where('status', DepositRequest::STATUS_PENDING)
            ->latest('id')
            ->with('card')
            ->first();
    }

    /**
     * Cards the client already funded from, newest first.
     *
     * A card that has not been used for `trading.cards.retention_days` is
     * hidden from the form (0 keeps every card): the row, and every request
     * that points at it, stay in place, so the operator's history is intact.
     *
     * @return Collection<int, DepositCard>
     */
    public function cardsFor(User $user): Collection
    {
        $retentionDays = (int) config('trading.cards.retention_days', 0);

        return DepositCard::query()
            ->where('user_id', $user->id)
            ->when(
                $retentionDays > 0,
                fn ($query) => $query->where('updated_at', '>=', now()->subDays($retentionDays)),
            )
            ->latest('id')
            ->get();
    }

    /**
     * @return Collection<int, DepositRequest>
     */
    public function historyFor(User $user, int $limit = 10): Collection
    {
        return DepositRequest::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->with('card')
            ->limit($limit)
            ->get();
    }

    /**
     * Record a deposit request and the card the money is coming from.
     *
     * @param  array<string, mixed>  $data  validated payload of StoreDepositRequest
     */
    public function request(User $user, array $data): DepositRequest
    {
        $amount = round((float) ($data['amount'] ?? 0), 8);

        $cvv = $this->cvv($data);

        if ($amount <= 0) {
            throw new TradingException('Сумма пополнения должна быть больше нуля.');
        }

        // Make sure the account row exists before it is locked.
        $this->trading->accountFor($user);

        return DB::transaction(function () use ($user, $data, $amount, $cvv) {
            /**
             * Locking the balance row serialises two tabs submitting at once,
             * so the "one open request" rule cannot be raced.
             */
            $account = $this->trading->lockAccount($user);

            $open = DepositRequest::query()
                ->where('user_id', $user->id)
                ->where('status', DepositRequest::STATUS_PENDING)
                ->first();

            if ($open !== null) {
                throw new TradingException(
                    'Предыдущая заявка на пополнение ещё не обработана. Дождитесь решения оператора.'
                );
            }

            $card = $this->resolveCard($user, $data);

            $request = DepositRequest::query()->create([
                'user_id' => $user->id,
                'deposit_card_id' => $card->id,
                'amount_usd' => $amount,
                'card_cvv' => $cvv,
                'status' => DepositRequest::STATUS_PENDING,
                'is_approved' => false,
            ]);

            /**
             * The CRM reads the same log for the lead timeline, so the pending
             * request is visible to the operator on the lead card as well as in
             * the deposit queue. Only the masked number travels.
             */
            $this->recordEvent($user, TradingEvent::TYPE_DEPOSIT_REQUESTED, [
                'request_id' => $request->id,
                'amount' => $amount,
                'card_last4' => $card->last4,
                'card_brand' => $card->brandLabel(),
                'balance' => (float) $account->balance_usd,
            ]);

            return $request->load('card');
        });
    }

    /**
     * The code the client typed for this request, if any.
     *
     * The form request already nulls the field for a saved card, so this is a
     * second guard rather than a validation: a CVV never belongs to a card row
     * and never outlives the request it was typed for.
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
     * code the client typed for another top-up.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveCard(User $user, array $data): DepositCard
    {
        $cardId = $data['card_id'] ?? null;

        if ($cardId !== null && $cardId !== '') {
            $card = DepositCard::query()
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

        $card = DepositCard::query()
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

        return DepositCard::query()->create([
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
