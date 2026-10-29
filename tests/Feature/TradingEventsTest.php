<?php

namespace Tests\Feature;

use App\Models\PriceHistory;
use App\Models\TradingEvent;
use App\Models\TradingPosition;
use App\Models\User;
use App\Services\TradingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * The trading app publishes every client action into trading_events, the
 * table Krayin CRM reads from the shared database. These tests use
 * DatabaseTransactions for the same reason TradingFlowTest does: the
 * schema and the users table belong to the CRM.
 */
class TradingEventsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (
            ! DB::getSchemaBuilder()->hasTable('roles')
            || ! DB::getSchemaBuilder()->hasTable('trading_events')
        ) {
            $this->markTestSkipped('CRM schema with the trading_events table is required.');
        }
    }

    private function makeTrader(): User
    {
        $roleId = DB::table('roles')->insertGetId([
            'name' => 'Trading events test',
            'description' => 'Temporary role created by TradingEventsTest',
            'permission_type' => 'all',
            'permissions' => json_encode(['leads' => ['create']]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::factory()->create([
            'role_id' => $roleId,
            'status' => 1,
        ]);
    }

    private function seedPrice(string $symbol, float $price, int $offsetSeconds = 0): void
    {
        PriceHistory::query()->create([
            'symbol' => $symbol,
            'price' => $price,
            'recorded_at' => now()->addSeconds($offsetSeconds),
        ]);
    }

    public function test_deposit_records_an_event(): void
    {
        $user = $this->makeTrader();
        $initial = (float) config('trading.initial_balance', 0);

        app(TradingService::class)->deposit($user, 250.0);

        $event = TradingEvent::query()->where('user_id', $user->id)->sole();

        $this->assertSame(TradingEvent::TYPE_DEPOSIT, $event->event_type);
        $this->assertSame(250.0, (float) $event->payload['amount']);
        $this->assertSame($initial, (float) $event->payload['balance_before']);
        $this->assertSame($initial + 250.0, (float) $event->payload['balance_after']);
        $this->assertNotNull($event->created_at);
    }

    public function test_opening_a_position_records_an_event(): void
    {
        $user = $this->makeTrader();
        $initial = (float) config('trading.initial_balance', 0);

        PriceHistory::query()->where('symbol', 'BTC')->delete();
        $this->seedPrice('BTC', 100.0);

        $this->actingAs($user)->post('/positions', [
            'symbol' => 'BTC',
            'side' => 'buy',
            'amount' => 2,
        ])->assertSessionHasNoErrors();

        $position = TradingPosition::query()->where('user_id', $user->id)->sole();
        $event = TradingEvent::query()->where('user_id', $user->id)->sole();

        $this->assertSame(TradingEvent::TYPE_POSITION_OPENED, $event->event_type);
        $this->assertSame($position->id, $event->payload['position_id']);
        $this->assertSame('BTC', $event->payload['symbol']);
        $this->assertSame('buy', $event->payload['side']);
        $this->assertSame(2.0, (float) $event->payload['amount']);
        $this->assertSame(100.0, (float) $event->payload['entry_price']);
        $this->assertSame(200.0, (float) $event->payload['notional']);
        $this->assertSame($initial - 200.0, (float) $event->payload['balance_after']);
    }

    public function test_closing_a_position_records_an_event(): void
    {
        $user = $this->makeTrader();
        $initial = (float) config('trading.initial_balance', 0);

        PriceHistory::query()->where('symbol', 'BTC')->delete();
        $this->seedPrice('BTC', 100.0, -60);

        $this->actingAs($user)->post('/positions', [
            'symbol' => 'BTC',
            'side' => 'buy',
            'amount' => 2,
        ])->assertSessionHasNoErrors();

        $position = TradingPosition::query()->where('user_id', $user->id)->sole();
        $this->seedPrice('BTC', 110.0);

        $this->actingAs($user)
            ->post(route('positions.close', $position))
            ->assertSessionHasNoErrors();

        $event = TradingEvent::query()
            ->where('user_id', $user->id)
            ->where('event_type', TradingEvent::TYPE_POSITION_CLOSED)
            ->sole();

        $this->assertSame($position->id, $event->payload['position_id']);
        $this->assertSame('BTC', $event->payload['symbol']);
        $this->assertSame('buy', $event->payload['side']);
        $this->assertSame(100.0, (float) $event->payload['entry_price']);
        $this->assertSame(110.0, (float) $event->payload['close_price']);
        $this->assertSame(200.0, (float) $event->payload['notional']);
        $this->assertSame(20.0, (float) $event->payload['pnl']);
        $this->assertSame($initial + 20.0, (float) $event->payload['balance_after']);
    }

    public function test_rejected_trade_records_no_event(): void
    {
        $user = $this->makeTrader();

        PriceHistory::query()->where('symbol', 'BTC')->delete();
        $this->seedPrice('BTC', 5000.0);

        $this->actingAs($user)
            ->post('/positions', [
                'symbol' => 'BTC',
                'side' => 'buy',
                'amount' => 3,
            ])
            ->assertSessionHasErrors('trade');

        $this->assertSame(0, TradingEvent::query()->where('user_id', $user->id)->count());
    }

    public function test_event_is_rolled_back_with_the_action(): void
    {
        $user = $this->makeTrader();
        $trading = app(TradingService::class);

        try {
            DB::transaction(function () use ($trading, $user): void {
                $trading->deposit($user, 500.0);

                $this->assertSame(1, TradingEvent::query()->where('user_id', $user->id)->count());

                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('rollback', $exception->getMessage());
        }

        // Both the account and the event must be gone: the event is written
        // inside the action's transaction, not after it.
        $this->assertSame(0, TradingEvent::query()->where('user_id', $user->id)->count());
        $this->assertDatabaseMissing('trading_accounts', ['user_id' => $user->id]);
    }
}
