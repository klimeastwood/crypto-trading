<?php

namespace Tests\Feature;

use App\Models\TradingAccount;
use App\Models\TradingEvent;
use App\Models\User;
use App\Models\WithdrawalCard;
use App\Models\WithdrawalRequest;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The endpoint Krayin CRM calls to change a trader balance by hand.
 *
 * The tests run against the shared development schema (like TradingEventsTest),
 * because trading_accounts and trading_events live next to the CRM tables.
 */
class BridgeBalanceTest extends TestCase
{
    use DatabaseTransactions;

    private const TOKEN = 'test-bridge-token';

    protected function setUp(): void
    {
        parent::setUp();

        if (
            ! DB::getSchemaBuilder()->hasTable('roles')
            || ! DB::getSchemaBuilder()->hasTable('trading_accounts')
            || ! DB::getSchemaBuilder()->hasTable('trading_events')
            || ! DB::getSchemaBuilder()->hasTable('withdrawal_requests')
        ) {
            $this->markTestSkipped('CRM schema with the trading tables is required.');
        }

        config(['trading.bridge.token' => self::TOKEN]);
    }

    private function makeTrader(): User
    {
        $roleId = DB::table('roles')->insertGetId([
            'name' => 'Bridge balance test',
            'description' => 'Temporary role created by BridgeBalanceTest',
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

    /**
     * A pending payout of the given trader, with the card it should be paid to.
     */
    private function makePayout(User $user, float $amount = 100.0): WithdrawalRequest
    {
        $card = WithdrawalCard::query()->create([
            'user_id' => $user->id,
            'holder_name' => 'IVAN IVANOV',
            'brand' => 'visa',
            'last4' => '4242',
            'expiry' => '12/30',
            'pan_encrypted' => '',
            'fingerprint' => bin2hex(random_bytes(16)),
        ]);

        return WithdrawalRequest::query()->create([
            'user_id' => $user->id,
            'withdrawal_card_id' => $card->id,
            'amount_usd' => $amount,
            'method' => WithdrawalRequest::METHOD_CARD,
            'card_cvv' => '987',
            'status' => WithdrawalRequest::STATUS_PENDING,
            'is_approved' => false,
        ]);
    }

    /**
     * Pin the balance instead of relying on the configured starting amount, so
     * a debit assertion is exact on any installation.
     */
    private function fund(User $user, float $amount): void
    {
        TradingAccount::query()->updateOrCreate(
            ['user_id' => $user->id],
            ['balance_usd' => $amount],
        );
    }

    private function balanceOf(User $user): float
    {
        return (float) TradingAccount::query()
            ->where('user_id', $user->id)
            ->value('balance_usd');
    }

    public function test_request_without_a_token_is_rejected(): void
    {
        $user = $this->makeTrader();

        $this->postJson('/api/balance/adjust', [
            'user_id' => $user->id,
            'amount' => 100,
            'reason' => 'Bonus',
        ])->assertStatus(401);

        $this->assertSame(0, TradingEvent::query()->where('user_id', $user->id)->count());
    }

    public function test_installation_without_a_token_disables_the_endpoint(): void
    {
        config(['trading.bridge.token' => '']);

        $user = $this->makeTrader();

        $this->postJson('/api/balance/adjust', [
            'user_id' => $user->id,
            'amount' => 100,
            'reason' => 'Bonus',
        ], ['Authorization' => 'Bearer anything'])->assertStatus(503);
    }

    public function test_a_positive_adjustment_credits_the_balance_and_records_an_event(): void
    {
        $user = $this->makeTrader();
        $initial = (float) config('trading.initial_balance', 0);

        $this->postJson('/api/balance/adjust', [
            'user_id' => $user->id,
            'amount' => 1000,
            'reason' => 'Бонус за депозит',
            'adjustment_id' => 77,
            'operator_name' => 'Иван Петров',
        ], ['Authorization' => 'Bearer '.self::TOKEN])
            ->assertOk()
            ->assertJsonPath('applied', true)
            ->assertJsonPath('balance_before', (int) $initial)
            ->assertJsonPath('balance_after', (int) ($initial + 1000));

        $event = TradingEvent::query()->where('user_id', $user->id)->sole();

        $this->assertSame(TradingEvent::TYPE_BALANCE_ADJUSTMENT, $event->event_type);
        $this->assertSame('77', $event->payload['reference']);
        $this->assertSame(1000.0, (float) $event->payload['amount']);
        $this->assertSame('Бонус за депозит', $event->payload['reason']);
        $this->assertSame('Иван Петров', $event->payload['operator_name']);
        $this->assertSame($initial + 1000.0, (float) $event->payload['balance_after']);
    }

    public function test_a_repeated_adjustment_id_does_not_credit_twice(): void
    {
        $user = $this->makeTrader();
        $initial = (float) config('trading.initial_balance', 0);

        $payload = [
            'user_id' => $user->id,
            'amount' => 500,
            'reason' => 'Bonus',
            'adjustment_id' => 88,
        ];

        $this->postJson('/api/balance/adjust', $payload, ['Authorization' => 'Bearer '.self::TOKEN])
            ->assertOk()
            ->assertJsonPath('applied', true);

        $this->postJson('/api/balance/adjust', $payload, ['Authorization' => 'Bearer '.self::TOKEN])
            ->assertOk()
            ->assertJsonPath('applied', false)
            ->assertJsonPath('balance_after', (int) ($initial + 500));

        $this->assertSame(1, TradingEvent::query()
            ->where('user_id', $user->id)
            ->where('event_type', TradingEvent::TYPE_BALANCE_ADJUSTMENT)
            ->count());
    }

    public function test_a_write_off_below_zero_is_rejected(): void
    {
        $user = $this->makeTrader();

        $this->postJson('/api/balance/adjust', [
            'user_id' => $user->id,
            'amount' => -999999,
            'reason' => 'Списание',
        ], ['Authorization' => 'Bearer '.self::TOKEN])
            ->assertStatus(422)
            ->assertJsonStructure(['message']);

        $this->assertSame(0, TradingEvent::query()->where('user_id', $user->id)->count());
    }

    public function test_a_zero_amount_is_rejected_by_validation(): void
    {
        $user = $this->makeTrader();

        $this->postJson('/api/balance/adjust', [
            'user_id' => $user->id,
            'amount' => 0,
            'reason' => 'Bonus',
        ], ['Authorization' => 'Bearer '.self::TOKEN])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_a_payout_decision_approves_the_request(): void
    {
        $user = $this->makeTrader();
        $payout = $this->makePayout($user);

        $this->postJson('/api/withdrawals/review', [
            'request_id' => $payout->id,
            'status' => WithdrawalRequest::STATUS_APPROVED,
            'note' => 'Проверено по карте',
            'reviewer_id' => $user->id,
        ], ['Authorization' => 'Bearer '.self::TOKEN])
            ->assertOk()
            ->assertJsonPath('request_id', $payout->id)
            ->assertJsonPath('status', WithdrawalRequest::STATUS_APPROVED)
            ->assertJsonPath('is_approved', true)
            ->assertJsonPath('applied', true);

        $payout->refresh();

        $this->assertSame(WithdrawalRequest::STATUS_APPROVED, $payout->status);
        $this->assertTrue($payout->is_approved);
        $this->assertSame($user->id, $payout->reviewed_by);
        $this->assertSame('Проверено по карте', $payout->review_note);
        $this->assertNotNull($payout->reviewed_at);

        // No money moves on review: the back office pays the request out.
        $this->assertSame(0, TradingEvent::query()->where('user_id', $user->id)->count());
    }

    public function test_a_replayed_decision_is_answered_without_being_applied_again(): void
    {
        $user = $this->makeTrader();
        $payout = $this->makePayout($user);

        $this->postJson('/api/withdrawals/review', [
            'request_id' => $payout->id,
            'status' => WithdrawalRequest::STATUS_REJECTED,
            'note' => 'Первый комментарий',
        ], ['Authorization' => 'Bearer '.self::TOKEN])
            ->assertOk()
            ->assertJsonPath('applied', true);

        $this->postJson('/api/withdrawals/review', [
            'request_id' => $payout->id,
            'status' => WithdrawalRequest::STATUS_REJECTED,
            'note' => 'Повтор после таймаута',
        ], ['Authorization' => 'Bearer '.self::TOKEN])
            ->assertOk()
            ->assertJsonPath('applied', false)
            ->assertJsonPath('status', WithdrawalRequest::STATUS_REJECTED);

        $payout->refresh();

        // The replay cannot overwrite the note of the first delivery.
        $this->assertSame('Первый комментарий', $payout->review_note);
    }

    public function test_a_different_decision_on_a_reviewed_payout_is_refused(): void
    {
        $user = $this->makeTrader();
        $payout = $this->makePayout($user);

        $this->postJson('/api/withdrawals/review', [
            'request_id' => $payout->id,
            'status' => WithdrawalRequest::STATUS_APPROVED,
        ], ['Authorization' => 'Bearer '.self::TOKEN])
            ->assertOk();

        $this->postJson('/api/withdrawals/review', [
            'request_id' => $payout->id,
            'status' => WithdrawalRequest::STATUS_REJECTED,
            'note' => 'Передумали',
        ], ['Authorization' => 'Bearer '.self::TOKEN])
            ->assertStatus(422)
            ->assertJsonStructure(['message']);

        $payout->refresh();

        $this->assertSame(WithdrawalRequest::STATUS_APPROVED, $payout->status);
        $this->assertNull($payout->review_note);
    }

    public function test_only_the_contract_statuses_are_accepted(): void
    {
        $user = $this->makeTrader();
        $payout = $this->makePayout($user);

        $this->postJson('/api/withdrawals/review', [
            'request_id' => $payout->id,
            'status' => 'cancelled',
        ], ['Authorization' => 'Bearer '.self::TOKEN])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $payout->refresh();

        $this->assertSame(WithdrawalRequest::STATUS_PENDING, $payout->status);
    }

    public function test_an_approved_payout_is_marked_paid_without_touching_the_approval(): void
    {
        $user = $this->makeTrader();
        $this->fund($user, 500);
        $payout = $this->makePayout($user);

        $this->postJson('/api/withdrawals/review', [
            'request_id' => $payout->id,
            'status' => WithdrawalRequest::STATUS_APPROVED,
            'note' => 'Проверено по карте',
            'reviewer_id' => $user->id,
        ], ['Authorization' => 'Bearer '.self::TOKEN])
            ->assertOk()
            ->assertJsonPath('applied', true)
            ->assertJsonPath('balance_before', null)
            ->assertJsonPath('balance_after', null);

        $approvedAt = $payout->refresh()->reviewed_at;

        // The approval alone takes nothing: the money is still on the account.
        $this->assertSame(500.0, $this->balanceOf($user));

        $this->postJson('/api/withdrawals/review', [
            'request_id' => $payout->id,
            'status' => WithdrawalRequest::STATUS_PAID,
        ], ['Authorization' => 'Bearer '.self::TOKEN])
            ->assertOk()
            ->assertJsonPath('status', WithdrawalRequest::STATUS_PAID)
            ->assertJsonPath('is_approved', true)
            ->assertJsonPath('balance_before', 500)
            ->assertJsonPath('balance_after', 400)
            ->assertJsonPath('applied', true);

        $payout->refresh();

        $this->assertSame(WithdrawalRequest::STATUS_PAID, $payout->status);
        $this->assertTrue($payout->is_approved);
        $this->assertSame($user->id, $payout->reviewed_by);
        $this->assertSame('Проверено по карте', $payout->review_note);
        $this->assertEquals($approvedAt, $payout->reviewed_at);
        $this->assertSame('Выплачено', $payout->statusLabel());
        $this->assertFalse($payout->isOpen());
        $this->assertSame(400.0, $this->balanceOf($user));
    }

    public function test_a_completed_payout_of_the_whole_balance_leaves_zero(): void
    {
        $user = $this->makeTrader();
        $this->fund($user, 4000);
        $payout = $this->makePayout($user, 4000);

        $this->postJson('/api/withdrawals/review', [
            'request_id' => $payout->id,
            'status' => WithdrawalRequest::STATUS_APPROVED,
        ], ['Authorization' => 'Bearer '.self::TOKEN])->assertOk();

        $this->postJson('/api/withdrawals/review', [
            'request_id' => $payout->id,
            'status' => WithdrawalRequest::STATUS_PAID,
        ], ['Authorization' => 'Bearer '.self::TOKEN])
            ->assertOk()
            ->assertJsonPath('applied', true)
            ->assertJsonPath('balance_before', 4000)
            ->assertJsonPath('balance_after', 0);

        $this->assertSame('0.00000000', (string) TradingAccount::query()
            ->where('user_id', $user->id)
            ->value('balance_usd'));
        $this->assertSame(WithdrawalRequest::STATUS_PAID, $payout->refresh()->status);
    }

    public function test_a_completed_payout_that_the_balance_can_no_longer_cover_is_refused(): void
    {
        $user = $this->makeTrader();
        $this->fund($user, 50);
        $payout = $this->makePayout($user);

        $this->postJson('/api/withdrawals/review', [
            'request_id' => $payout->id,
            'status' => WithdrawalRequest::STATUS_APPROVED,
        ], ['Authorization' => 'Bearer '.self::TOKEN])->assertOk();

        // A losing trade or another payout can empty the account between the
        // approval and the confirmation, and the debit may not go below zero.
        $this->postJson('/api/withdrawals/review', [
            'request_id' => $payout->id,
            'status' => WithdrawalRequest::STATUS_PAID,
        ], ['Authorization' => 'Bearer '.self::TOKEN])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Недостаточно средств: сумма вывода $100.00 больше доступного баланса $50.00.');

        $payout->refresh();

        $this->assertSame(WithdrawalRequest::STATUS_APPROVED, $payout->status);
        $this->assertTrue($payout->isApproved());
        $this->assertSame(50.0, $this->balanceOf($user));

        // The refusal is not final: topping the account up lets the CRM retry.
        $this->fund($user, 150);

        $this->postJson('/api/withdrawals/review', [
            'request_id' => $payout->id,
            'status' => WithdrawalRequest::STATUS_PAID,
        ], ['Authorization' => 'Bearer '.self::TOKEN])
            ->assertOk()
            ->assertJsonPath('applied', true)
            ->assertJsonPath('balance_after', 50);

        $this->assertSame(WithdrawalRequest::STATUS_PAID, $payout->refresh()->status);
        $this->assertSame(50.0, $this->balanceOf($user));
    }

    public function test_a_pending_payout_cannot_be_marked_paid(): void
    {
        $user = $this->makeTrader();
        $this->fund($user, 100);
        $payout = $this->makePayout($user);

        $this->postJson('/api/withdrawals/review', [
            'request_id' => $payout->id,
            'status' => WithdrawalRequest::STATUS_PAID,
        ], ['Authorization' => 'Bearer '.self::TOKEN])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Заявка уже обработана: На проверке.');

        $payout->refresh();

        $this->assertSame(WithdrawalRequest::STATUS_PENDING, $payout->status);
        $this->assertFalse($payout->is_approved);
        $this->assertNull($payout->reviewed_at);
        $this->assertSame(100.0, $this->balanceOf($user));
    }

    public function test_a_replayed_completion_is_answered_without_being_applied_again(): void
    {
        $user = $this->makeTrader();
        $this->fund($user, 500);
        $payout = $this->makePayout($user);

        $this->postJson('/api/withdrawals/review', [
            'request_id' => $payout->id,
            'status' => WithdrawalRequest::STATUS_APPROVED,
        ], ['Authorization' => 'Bearer '.self::TOKEN])->assertOk();

        $this->postJson('/api/withdrawals/review', [
            'request_id' => $payout->id,
            'status' => WithdrawalRequest::STATUS_PAID,
        ], ['Authorization' => 'Bearer '.self::TOKEN])
            ->assertOk()
            ->assertJsonPath('applied', true)
            ->assertJsonPath('balance_after', 400);

        $this->assertSame(400.0, $this->balanceOf($user));

        // The retry reports no balance movement and touches neither the
        // account nor the review: nothing was applied a second time.
        $this->postJson('/api/withdrawals/review', [
            'request_id' => $payout->id,
            'status' => WithdrawalRequest::STATUS_PAID,
        ], ['Authorization' => 'Bearer '.self::TOKEN])
            ->assertOk()
            ->assertJsonPath('applied', false)
            ->assertJsonPath('status', WithdrawalRequest::STATUS_PAID)
            ->assertJsonPath('balance_before', null)
            ->assertJsonPath('balance_after', null);

        $this->assertSame(WithdrawalRequest::STATUS_PAID, $payout->refresh()->status);
        $this->assertSame(400.0, $this->balanceOf($user));
    }

    public function test_an_unknown_payout_is_rejected_by_validation(): void
    {
        $this->postJson('/api/withdrawals/review', [
            'request_id' => 999999999,
            'status' => WithdrawalRequest::STATUS_APPROVED,
        ], ['Authorization' => 'Bearer '.self::TOKEN])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['request_id']);
    }

    public function test_an_unknown_reviewer_is_rejected_by_validation(): void
    {
        $user = $this->makeTrader();
        $payout = $this->makePayout($user);

        $this->postJson('/api/withdrawals/review', [
            'request_id' => $payout->id,
            'status' => WithdrawalRequest::STATUS_APPROVED,
            'reviewer_id' => 999999999,
        ], ['Authorization' => 'Bearer '.self::TOKEN])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reviewer_id']);

        $payout->refresh();

        $this->assertSame(WithdrawalRequest::STATUS_PENDING, $payout->status);
    }

    public function test_a_payout_decision_requires_the_bridge_token(): void
    {
        $user = $this->makeTrader();
        $payout = $this->makePayout($user);

        $this->postJson('/api/withdrawals/review', [
            'request_id' => $payout->id,
            'status' => WithdrawalRequest::STATUS_APPROVED,
        ])->assertStatus(401);

        $payout->refresh();

        $this->assertSame(WithdrawalRequest::STATUS_PENDING, $payout->status);
    }
}
