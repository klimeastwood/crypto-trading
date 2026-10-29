<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A client action published for Krayin CRM through the shared database.
 *
 * Rows are append-only: only created_at exists, and the payload keeps the
 * numbers of the operation (amounts, prices, balance) so the CRM does not
 * have to join the trading tables to show what happened.
 */
class TradingEvent extends Model
{
    public const TYPE_DEPOSIT = 'deposit';

    public const TYPE_POSITION_OPENED = 'position.opened';

    public const TYPE_POSITION_CLOSED = 'position.closed';

    /**
     * A CRM operator changed the balance by hand.
     *
     * The payload carries a `reference` (the CRM adjustment id), so a repeated
     * request cannot credit the same bonus twice.
     */
    public const TYPE_BALANCE_ADJUSTMENT = 'balance.adjustment';

    /**
     * A client staged a payout request; the money does not move until an
     * operator approves it in the CRM.
     *
     * The payload carries a masked card (last four digits) or a wallet
     * address — never a card number and never a CVV.
     */
    public const TYPE_WITHDRAWAL_REQUESTED = 'withdrawal.requested';

    /**
     * A client staged a deposit; the money does not reach the balance until an
     * operator confirms it in the CRM.
     *
     * The payload carries a masked card (last four digits) and the amount, so
     * the CRM timeline shows what is waiting for review without publishing a
     * card number.
     */
    public const TYPE_DEPOSIT_REQUESTED = 'deposit.requested';

    /**
     * Events are immutable: no updated_at column is written.
     */
    public const UPDATED_AT = null;

    protected $table = 'trading_events';

    protected $fillable = [
        'user_id',
        'event_type',
        'payload',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
