<?php

namespace Tests\Unit;

use App\Models\TradingPosition;
use PHPUnit\Framework\TestCase;

class TradingPositionTest extends TestCase
{
    private function position(string $side, string $amount, string $entryPrice): TradingPosition
    {
        return new TradingPosition([
            'side' => $side,
            'amount' => $amount,
            'entry_price' => $entryPrice,
        ]);
    }

    public function test_buy_position_profits_when_price_rises(): void
    {
        $position = $this->position('buy', '2', '100');

        $this->assertSame(200.0, $position->pnl(200.0));
        $this->assertSame(-100.0, $position->pnl(50.0));
    }

    public function test_sell_position_profits_when_price_falls(): void
    {
        $position = $this->position('sell', '3', '100');

        $this->assertSame(150.0, $position->pnl(50.0));
        $this->assertSame(-60.0, $position->pnl(120.0));
    }

    public function test_pnl_is_null_without_a_price(): void
    {
        $this->assertNull($this->position('buy', '1', '100')->pnl(null));
    }

    public function test_notional_is_the_blocked_collateral(): void
    {
        $this->assertSame(150.0, $this->position('buy', '1.5', '100')->notional());
    }
}
