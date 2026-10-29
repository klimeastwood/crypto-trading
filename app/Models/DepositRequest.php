<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A deposit the client staged and an operator has not confirmed yet.
 *
 * The request is a staging record: creating it does not touch the balance. The
 * CRM operator checks that the money arrived on the card and approves it
 * (`status` = approved, `is_approved` = true); the balance is credited by the
 * trading side as a `deposit` event at that moment. Until then the client sees
 * the request in the cabinet and cannot create a second one.
 */
class DepositRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $table = 'deposit_requests';

    protected $fillable = [
        'user_id',
        'deposit_card_id',
        'amount_usd',
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
            'deposit_card_id' => 'integer',
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
        return $this->belongsTo(DepositCard::class, 'deposit_card_id');
    }

    /**
     * A request the client cannot repeat: it waits for the operator.
     */
    public function isOpen(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    /**
     * Russian label of the status for the cabinet.
     */
    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_APPROVED => 'Пополнение подтверждено',
            self::STATUS_REJECTED => 'Отклонено',
            default => 'На проверке',
        };
    }

    /**
     * CSS modifier that follows the status, matching the badges of the cabinet.
     */
    public function statusTone(): string
    {
        return match ($this->status) {
            self::STATUS_APPROVED => 'is-success',
            self::STATUS_REJECTED => 'is-danger',
            default => 'is-waiting',
        };
    }

    /**
     * Where the money came from, with the number masked for the client.
     */
    public function requisites(): string
    {
        return $this->card?->maskedNumber() ?? 'Банковская карта';
    }
}
