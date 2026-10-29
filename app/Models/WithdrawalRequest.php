<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A payout the client asked for and an operator has not finished yet.
 *
 * The request is a staging record: creating it does not touch the balance.
 * The CRM operator approves it (`status` = approved, `is_approved` = true) and
 * the back office makes the transfer. When the CRM confirms it, the request
 * becomes `paid` and the amount is debited from the trader's balance once, by
 * `WithdrawalService::review()`. The cabinet reads `paid` as «Выплачено».
 * Until the request is paid or rejected the client cannot create a second one.
 */
class WithdrawalRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_PAID = 'paid';

    public const METHOD_CARD = 'card';

    public const METHOD_CRYPTO = 'crypto';

    protected $table = 'withdrawal_requests';

    protected $fillable = [
        'user_id',
        'withdrawal_card_id',
        'amount_usd',
        'method',
        'wallet_address',
        'card_cvv',
        'status',
        'is_approved',
        'reviewed_by',
        'reviewed_at',
        'review_note',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'withdrawal_card_id' => 'integer',
            'amount_usd' => 'decimal:8',
            'is_approved' => 'boolean',
            'reviewed_by' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(WithdrawalCard::class, 'withdrawal_card_id');
    }

    /**
     * A request the client cannot repeat: it waits for the operator.
     */
    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_APPROVED], true);
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    /**
     * A request nobody has decided yet: the only state the desk may review.
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Russian label of the status for the cabinet.
     */
    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_APPROVED => 'Одобрено',
            self::STATUS_REJECTED => 'Отклонено',
            self::STATUS_PAID => 'Выплачено',
            default => 'На проверке',
        };
    }

    /**
     * CSS modifier that follows the status, matching the badges of the cabinet.
     */
    public function statusTone(): string
    {
        return match ($this->status) {
            self::STATUS_APPROVED, self::STATUS_PAID => 'is-success',
            self::STATUS_REJECTED => 'is-danger',
            default => 'is-waiting',
        };
    }

    /**
     * Where the money is going, with the number masked for the client.
     */
    public function requisites(): string
    {
        if ($this->method === self::METHOD_CRYPTO) {
            $address = (string) $this->wallet_address;

            return $address === ''
                ? 'Криптокошелёк'
                : substr($address, 0, 6).'…'.substr($address, -4);
        }

        return $this->card?->maskedNumber() ?? 'Банковская карта';
    }

    /**
     * "Банковская карта" / "Криптокошелёк" for the history table.
     */
    public function methodLabel(): string
    {
        return $this->method === self::METHOD_CRYPTO ? 'Криптокошелёк' : 'Карта';
    }
}
