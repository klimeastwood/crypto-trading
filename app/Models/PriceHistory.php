<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PriceHistory extends Model
{
    /**
     * Rows are append-only market data; "recorded_at" is the only
     * meaningful timestamp, so Eloquent timestamps are disabled.
     */
    public $timestamps = false;

    protected $table = 'price_history';

    protected $fillable = [
        'symbol',
        'price',
        'recorded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:8',
            'recorded_at' => 'datetime',
        ];
    }
}
