<?php

namespace Tests\Feature;

use App\Models\PriceHistory;
use App\Services\MarketDataService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MarketDataServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_it_stores_history_and_skips_duplicate_ticks(): void
    {
        // The shared database may already contain imported ETH prices.
        PriceHistory::query()->where('symbol', 'ETH')->delete();

        $now = now();

        Http::fake([
            'api.coingecko.com/*' => Http::response([
                'prices' => [
                    [(int) $now->copy()->subDay()->getTimestampMs(), 100.0],
                    [(int) $now->getTimestampMs(), 101.5],
                    // CoinGecko often returns several ticks inside one second;
                    // the unique (symbol, recorded_at) index must not explode.
                    [(int) $now->getTimestampMs(), 102.0],
                ],
            ]),
        ]);

        $service = app(MarketDataService::class);

        $result = $service->sync('ETH', 'ethereum', 7);

        $this->assertSame(2, $result['points']);
        $this->assertSame(102.0, $result['latest']);

        // Running the hourly command again must upsert, not duplicate.
        $service->sync('ETH', 'ethereum', 7);

        $rows = PriceHistory::query()->where('symbol', 'ETH')->orderBy('recorded_at')->get();

        $this->assertCount(2, $rows);
        $this->assertSame(100.0, (float) $rows->first()->price);
        $this->assertSame(102.0, (float) $rows->last()->price);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/coins/ethereum/market_chart')
            && $request['days'] === 7);
    }
}
