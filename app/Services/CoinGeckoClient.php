<?php

namespace App\Services;

use App\Exceptions\CoinGeckoException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper around the public (keyless) CoinGecko API.
 *
 * Free tier is rate limited, so the client keeps the footprint small:
 * one request per coin and a couple of retries for transient errors.
 */
class CoinGeckoClient
{
    /**
     * Historical prices for the last N days.
     *
     * @return list<array{timestamp: int, price: float}>
     *
     * @throws CoinGeckoException
     */
    public function marketChart(string $coinId, int $days): array
    {
        $url = rtrim((string) config('trading.api.base_url'), '/')
            .'/coins/'.rawurlencode($coinId).'/market_chart';

        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'User-Agent' => 'crypto-trading-platform/1.0 (+laravel)',
            ])
                ->timeout((int) config('trading.api.timeout', 15))
                ->retry(
                    (int) config('trading.api.retry_times', 2),
                    (int) config('trading.api.retry_delay_ms', 1500),
                )
                ->get($url, [
                    'vs_currency' => config('trading.vs_currency', 'usd'),
                    'days' => $days,
                ]);
        } catch (ConnectionException $exception) {
            throw new CoinGeckoException(
                'Не удалось подключиться к CoinGecko: '.$exception->getMessage(),
                previous: $exception,
            );
        }

        if ($response->status() === 429) {
            throw new CoinGeckoException(
                'CoinGecko ограничил частоту запросов (HTTP 429). Подождите минуту и повторите.'
            );
        }

        if ($response->failed()) {
            throw new CoinGeckoException(
                'CoinGecko вернул HTTP '.$response->status().' для «'.$coinId.'».'
            );
        }

        $prices = $response->json('prices');

        if (! is_array($prices) || $prices === []) {
            throw new CoinGeckoException(
                'CoinGecko вернул пустой ряд цен для «'.$coinId.'».'
            );
        }

        $points = [];

        foreach ($prices as $row) {
            if (! is_array($row) || count($row) < 2) {
                continue;
            }

            [$timestamp, $price] = $row;

            if (! is_numeric($timestamp) || ! is_numeric($price)) {
                continue;
            }

            $points[] = [
                'timestamp' => (int) $timestamp,
                'price' => (float) $price,
            ];
        }

        if ($points === []) {
            throw new CoinGeckoException(
                'Не удалось разобрать ответ CoinGecko для «'.$coinId.'».'
            );
        }

        return $points;
    }
}
