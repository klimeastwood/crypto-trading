<?php

namespace App\Console\Commands;

use App\Exceptions\CoinGeckoException;
use App\Services\MarketDataService;
use Illuminate\Console\Command;
use Throwable;

class FetchPrices extends Command
{
    /**
     * @var string
     */
    protected $signature = 'trading:fetch-prices
                            {--symbol=* : Internal symbols (BTC, ETH, ...). Default: all from config/trading.php}
                            {--days= : History depth in days. Default: config/trading.php}';

    /**
     * @var string
     */
    protected $description = 'Fetch historical crypto prices from the free CoinGecko API into price_history';

    public function handle(MarketDataService $marketData): int
    {
        $symbols = (array) config('trading.symbols', []);

        $requested = array_values(array_unique(array_map(
            fn ($symbol) => strtoupper((string) $symbol),
            (array) $this->option('symbol'),
        )));

        if ($requested !== []) {
            $symbols = array_intersect_key($symbols, array_flip($requested));

            if ($symbols === []) {
                $this->error('Неизвестные символы: '.implode(', ', $requested));

                return self::INVALID;
            }
        }

        if ($symbols === []) {
            $this->error('В config/trading.php не настроен ни один инструмент.');

            return self::INVALID;
        }

        $days = max(1, (int) ($this->option('days') ?: config('trading.history_days', 7)));

        $this->info('Загружаю историю за '.$days.' дн. с CoinGecko (валюта: '.config('trading.vs_currency').').');

        $rows = [];
        $failures = 0;

        foreach ($symbols as $symbol => $coinId) {
            try {
                $result = $marketData->sync($symbol, $coinId, $days);

                $rows[] = [
                    $symbol,
                    $coinId,
                    $result['points'],
                    '$'.number_format($result['latest'], 2),
                ];
            } catch (CoinGeckoException $exception) {
                $failures++;
                $this->warn('✗ '.$symbol.': '.$exception->getMessage());
            } catch (Throwable $exception) {
                $failures++;
                report($exception);
                $this->warn('✗ '.$symbol.': '.$exception->getMessage());
            }

            // Free tier is rate limited — do not hammer it.
            if (count($symbols) > 1) {
                usleep(350000);
            }
        }

        if ($rows !== []) {
            $this->table(
                ['Symbol', 'CoinGecko ID', 'Points', 'Latest price'],
                $rows,
            );
        }

        if ($failures === count($symbols)) {
            $this->error('Не удалось загрузить ни один инструмент.');

            return self::FAILURE;
        }

        if ($failures > 0) {
            $this->warn('Часть инструментов не загружена ('.$failures.' из '.count($symbols).').');
        }

        $this->info('Готово: история сохранена в price_history.');

        return self::SUCCESS;
    }
}
