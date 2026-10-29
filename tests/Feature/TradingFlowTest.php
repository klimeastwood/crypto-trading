<?php

namespace Tests\Feature;

use App\Models\PriceHistory;
use App\Models\TradingAccount;
use App\Models\TradingEvent;
use App\Models\TradingPosition;
use App\Models\User;
use App\Services\TradingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * These tests run against the shared krayin_crm database, so they use
 * DatabaseTransactions instead of RefreshDatabase: every row they create
 * (role, user, price, position) is rolled back and no CRM table is ever
 * truncated.
 */
class TradingFlowTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // The app shares the schema with Krayin CRM: without it (fresh
        // checkout before "php artisan migrate") there is nothing to test.
        if (! DB::getSchemaBuilder()->hasTable('roles')) {
            $this->markTestSkipped('CRM schema with the roles table is required.');
        }
    }

    private function makeTrader(bool $active = true): User
    {
        $roleId = DB::table('roles')->insertGetId([
            'name' => 'Trading test',
            'description' => 'Temporary role created by TradingFlowTest',
            'permission_type' => 'all',
            'permissions' => json_encode(['leads' => ['create']]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::factory()->create([
            'role_id' => $roleId,
            'status' => $active ? 1 : 0,
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

    private function balanceOf(User $user): float
    {
        return (float) TradingAccount::query()
            ->where('user_id', $user->id)
            ->value('balance_usd');
    }

    public function test_dashboard_requires_authentication(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_active_crm_user_can_log_in(): void
    {
        $user = $this->makeTrader();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_inactive_crm_user_cannot_log_in(): void
    {
        $user = $this->makeTrader(active: false);

        $this->from('/login')
            ->post('/login', [
                'email' => $user->email,
                'password' => 'password',
            ])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_dashboard_renders_for_a_trader(): void
    {
        $user = $this->makeTrader();
        $this->seedPrice('BTC', 65000.0);

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        $response->assertSee('Личный кабинет трейдера');
        $response->assertSee($user->email);
        $response->assertSee('id="priceChart"', false);
        $response->assertSee('cdn.jsdelivr.net/npm/chart.js', false);
        $response->assertSee('Открытых позиций нет.');

        $this->assertSame(10000.0, $this->balanceOf($user));
    }

    public function test_deposit_without_a_card_is_refused_and_does_not_credit_the_balance(): void
    {
        $user = $this->makeTrader();

        // The cabinet opens the account before the form is shown.
        app(TradingService::class)->accountFor($user);

        $this->assertSame(10000.0, $this->balanceOf($user));

        /**
         * The former instant top-up is gone: an amount alone is not a deposit
         * any more, and the balance must not move until an operator approves a
         * card-backed request in the CRM.
         */
        $this->actingAs($user)
            ->post('/deposit', ['amount' => 500])
            ->assertRedirect()
            ->assertSessionHasErrors('card_holder');

        $this->assertSame(10000.0, $this->balanceOf($user));
    }

    public function test_buy_position_blocks_collateral_and_returns_profit_on_close(): void
    {
        $user = $this->makeTrader();
        PriceHistory::query()->where('symbol', 'BTC')->delete();
        $this->seedPrice('BTC', 100.0, -60);

        $this->actingAs($user)
            ->post('/positions', [
                'symbol' => 'BTC',
                'side' => 'buy',
                'amount' => 2,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(9800.0, $this->balanceOf($user));

        $position = TradingPosition::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(TradingPosition::STATUS_OPEN, $position->status);
        $this->assertSame(100.0, (float) $position->entry_price);

        $this->seedPrice('BTC', 110.0);

        $this->actingAs($user)
            ->post(route('positions.close', $position))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // 9800 left + 200 collateral + (110 − 100) × 2 profit.
        $this->assertSame(10020.0, $this->balanceOf($user));

        $position->refresh();
        $this->assertSame(TradingPosition::STATUS_CLOSED, $position->status);
        $this->assertSame(110.0, (float) $position->close_price);
        $this->assertNotNull($position->closed_at);
    }

    public function test_sell_position_profits_when_price_drops(): void
    {
        $user = $this->makeTrader();
        PriceHistory::query()->where('symbol', 'ETH')->delete();
        $this->seedPrice('ETH', 100.0, -60);

        $this->actingAs($user)->post('/positions', [
            'symbol' => 'ETH',
            'side' => 'sell',
            'amount' => 1,
        ])->assertSessionHasNoErrors();

        $this->assertSame(9900.0, $this->balanceOf($user));

        $position = TradingPosition::query()->where('user_id', $user->id)->firstOrFail();
        $this->seedPrice('ETH', 80.0);

        $this->actingAs($user)
            ->post(route('positions.close', $position))
            ->assertSessionHasNoErrors();

        // 9900 left + 100 collateral + 20 profit.
        $this->assertSame(10020.0, $this->balanceOf($user));
    }

    public function test_position_is_rejected_when_balance_is_too_small(): void
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
            ->assertRedirect()
            ->assertSessionHasErrors('trade');

        $this->assertSame(0, TradingPosition::query()->where('user_id', $user->id)->count());
        $this->assertSame(10000.0, $this->balanceOf($user));
    }

    public function test_position_is_rejected_without_a_stored_price(): void
    {
        $user = $this->makeTrader();

        // The shared database may already contain imported SOL prices.
        PriceHistory::query()->where('symbol', 'SOL')->delete();

        $this->actingAs($user)
            ->post('/positions', [
                'symbol' => 'SOL',
                'side' => 'buy',
                'amount' => 1,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('trade');

        $this->assertSame(0, TradingPosition::query()->where('user_id', $user->id)->count());
    }

    public function test_trader_cannot_close_a_foreign_position(): void
    {
        $owner = $this->makeTrader();
        $intruder = $this->makeTrader();
        PriceHistory::query()->where('symbol', 'BTC')->delete();
        $this->seedPrice('BTC', 100.0);

        $this->actingAs($owner)->post('/positions', [
            'symbol' => 'BTC',
            'side' => 'buy',
            'amount' => 1,
        ])->assertSessionHasNoErrors();

        $position = TradingPosition::query()->where('user_id', $owner->id)->firstOrFail();

        $this->actingAs($intruder)
            ->post(route('positions.close', $position))
            ->assertForbidden();

        $this->assertSame(TradingPosition::STATUS_OPEN, $position->fresh()->status);
    }

    public function test_usd_sized_position_is_converted_at_the_entry_price(): void
    {
        $user = $this->makeTrader();
        PriceHistory::query()->where('symbol', 'BTC')->delete();
        $this->seedPrice('BTC', 2500.0, -60);

        $this->actingAs($user)
            ->post('/positions', [
                'symbol' => 'BTC',
                'side' => 'buy',
                'amount' => 1000,
                'size_unit' => 'usd',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // $1 000 at $2 500 is 0.4 BTC - the balance loses the dollars, not
        // 1 000 coins (which would cost $2 500 000).
        $position = TradingPosition::query()->where('user_id', $user->id)->sole();

        $this->assertEqualsWithDelta(0.4, (float) $position->amount, 1e-9);
        $this->assertSame(2500.0, (float) $position->entry_price);
        $this->assertSame(9000.0, $this->balanceOf($user));

        // The journal keeps both numbers, so the CRM can tell a $1 000 order
        // from a 1 000-coin one.
        $event = TradingEvent::query()
            ->where('user_id', $user->id)
            ->where('event_type', TradingEvent::TYPE_POSITION_OPENED)
            ->sole();

        $this->assertSame(TradingService::SIZE_UNIT_USD, $event->payload['size_unit']);
        $this->assertSame(1000.0, (float) $event->payload['requested_amount']);
        $this->assertSame(1000.0, (float) $event->payload['notional']);
    }

    public function test_size_unit_is_normalised_before_validation(): void
    {
        $user = $this->makeTrader();
        PriceHistory::query()->where('symbol', 'BTC')->delete();
        $this->seedPrice('BTC', 1000.0, -60);

        // A form or an API client may send " USD " in any case.
        $this->actingAs($user)
            ->post('/positions', [
                'symbol' => 'BTC',
                'side' => 'buy',
                'amount' => 500,
                'size_unit' => ' USD ',
            ])
            ->assertSessionHasNoErrors();

        $position = TradingPosition::query()->where('user_id', $user->id)->sole();

        $this->assertEqualsWithDelta(0.5, (float) $position->amount, 1e-9);
        $this->assertSame(9500.0, $this->balanceOf($user));
    }

    public function test_position_below_the_minimum_notional_is_rejected_in_both_units(): void
    {
        config(['trading.position.min_notional' => 500]);

        $user = $this->makeTrader();
        app(TradingService::class)->accountFor($user);

        PriceHistory::query()->where('symbol', 'BTC')->delete();
        $this->seedPrice('BTC', 100.0, -60);

        // $100 and 1 coin at $100 both hit the same $500 floor.
        $this->actingAs($user)
            ->post('/positions', [
                'symbol' => 'BTC',
                'side' => 'buy',
                'amount' => 100,
                'size_unit' => 'usd',
            ])
            ->assertSessionHasErrors('trade');

        $this->actingAs($user)
            ->post('/positions', [
                'symbol' => 'BTC',
                'side' => 'buy',
                'amount' => 1,
            ])
            ->assertSessionHasErrors('trade');

        $this->assertSame(0, TradingPosition::query()->where('user_id', $user->id)->count());
        $this->assertSame(10000.0, $this->balanceOf($user));
    }

    public function test_an_unknown_size_unit_is_rejected(): void
    {
        $user = $this->makeTrader();
        PriceHistory::query()->where('symbol', 'BTC')->delete();
        $this->seedPrice('BTC', 100.0, -60);

        $this->actingAs($user)
            ->post('/positions', [
                'symbol' => 'BTC',
                'side' => 'buy',
                'amount' => 1,
                'size_unit' => 'grams',
            ])
            ->assertSessionHasErrors('size_unit');

        $this->assertSame(0, TradingPosition::query()->where('user_id', $user->id)->count());
    }
}
