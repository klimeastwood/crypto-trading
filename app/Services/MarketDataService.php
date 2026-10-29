<?php

namespace App\Services;

use App\Models\PriceHistory;
use Illuminate\Support\Carbon;

/**
 * Pulls market history from CoinGecko and stores it in "price_history".
 */
class MarketDataService
{
    public function __construct(private readonly CoinGeckoClient $client) {}

    /**
     * @return array{symbol: string, points: int, latest: float}
     */
    public function sync(string $symbol, string $coinId, int $days): array
    {
        $points = $this->client->marketChart($coinId, $days);
        $timezone = (string) config('app.timezone', 'UTC');

        $rows = [];

        foreach ($points as $point) {
            $recordedAt = Carbon::createFromTimestampMs($point['timestamp'], $timezone);

            // CoinGecko sometimes returns several ticks within one second.
            // The unique (symbol, recorded_at) index needs one row per second.
            $rows[$recordedAt->format('Y-m-d H:i:s')] = [
                'symbol' => $symbol,
                'price' => $point['price'],
                'recorded_at' => $recordedAt,
            ];
        }

        foreach (array_chunk(array_values($rows), 500) as $chunk) {
            PriceHistory::query()->upsert(
                $chunk,
                ['symbol', 'recorded_at'],
                ['price'],
            );
        }

        return [
            'symbol' => $symbol,
            'points' => count($rows),
            'latest' => (float) end($points)['price'],
        ];
    }
}
