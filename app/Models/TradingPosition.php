<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradingPosition extends Model
{
    public const SIDE_BUY = 'buy';

    public const SIDE_SELL = 'sell';

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    protected $table = 'trading_positions';

    protected $fillable = [
        'user_id',
        'symbol',
        'side',
        'amount',
        'entry_price',
        'status',
        'close_price',
        'closed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:8',
            'entry_price' => 'decimal:8',
            'close_price' => 'decimal:8',
            'closed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    /**
     * The USD amount that was blocked on the account when the position
     * was opened (1x collateral, no leverage).
     */
    public function notional(): float
    {
        return round((float) $this->entry_price * (float) $this->amount, 8);
    }

    /**
     * Profit/loss in USD for the given market price.
     */
    public function pnl(?float $currentPrice): ?float
    {
        if ($currentPrice === null) {
            return null;
        }

        $entry = (float) $this->entry_price;
        $difference = $this->side === self::SIDE_BUY
            ? $currentPrice - $entry
            : $entry - $currentPrice;

        return round($difference * (float) $this->amount, 8);
    }
}
