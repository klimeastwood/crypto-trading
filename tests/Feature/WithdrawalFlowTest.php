<?php

namespace Tests\Feature;

use App\Models\TradingAccount;
use App\Models\TradingEvent;
use App\Models\User;
use App\Models\WithdrawalCard;
use App\Models\WithdrawalRequest;
use App\Services\WithdrawalService;
use App\Support\CardCipher;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The withdrawal flow of the client cabinet.
 *
 * Like TradingFlowTest these tests run against the shared krayin_crm schema and
 * use DatabaseTransactions, so every card, request and user created here is
 * rolled back and no CRM table is truncated.
 */
class WithdrawalFlowTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * A Visa test number that passes the Luhn checksum.
     */
    private const CARD = '4242424242424242';

    protected function setUp(): void
    {
        parent::setUp();

        if (! DB::getSchemaBuilder()->hasTable('roles')) {
            $this->markTestSkipped('CRM schema with the roles table is required.');
        }

        if (! DB::getSchemaBuilder()->hasTable('withdrawal_requests')) {
            $this->markTestSkipped('Run "php artisan migrate" before the withdrawal tests.');
        }
    }

    private function makeTrader(): User
    {
        $roleId = DB::table('roles')->insertGetId([
            'name' => 'Withdrawal test',
            'description' => 'Temporary role created by WithdrawalFlowTest',
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
     * @return array<string, mixed>
     */
    private function cardPayload(array $overrides = []): array
    {
        return array_merge([
            'amount' => 100,
            'method' => WithdrawalRequest::METHOD_CARD,
            'card_holder' => 'IVAN IVANOV',
            'card_number' => self::CARD,
            'card_expiry' => now()->addYears(2)->format('m/y'),
            'card_cvv' => '987',
        ], $overrides);
    }

    private function balanceOf(User $user): float
    {
        return (float) TradingAccount::query()
            ->where('user_id', $user->id)
            ->value('balance_usd');
    }

    public function test_card_request_stores_encrypted_card_and_leaves_balance_untouched(): void
    {
        $user = $this->makeTrader();

        $response = $this->actingAs($user)
            ->post('/withdrawals', $this->cardPayload());

        $response->assertRedirect()->assertSessionHasNoErrors();

        $request = WithdrawalRequest::query()->where('user_id', $user->id)->sole();

        $this->assertSame(WithdrawalRequest::STATUS_PENDING, $request->status);
        $this->assertFalse($request->is_approved);
        $this->assertSame('100.00000000', (string) $request->amount_usd);
        $this->assertSame(10000.0, $this->balanceOf($user));

        $card = WithdrawalCard::query()->where('user_id', $user->id)->sole();

        $this->assertSame('4242', $card->last4);
        $this->assertSame('visa', $card->brand);
        $this->assertSame(self::CARD, $card->pan());
        $this->assertNotSame(self::CARD, $card->pan_encrypted);
        $this->assertSame($card->id, $request->withdrawal_card_id);

        // The CVV lives on the request alone - the CRM desk reads it there - and
        // never on the card row, in the event payload or in the session.
        $this->assertSame('987', $request->card_cvv);

        $row = (string) json_encode(DB::table('withdrawal_cards')->where('user_id', $user->id)->first());
        $this->assertStringNotContainsString('987', $row);
        $this->assertStringNotContainsString(self::CARD, $row);
        $response->assertSessionMissing('_old_input');

        $event = TradingEvent::query()
            ->where('user_id', $user->id)
            ->where('event_type', TradingEvent::TYPE_WITHDRAWAL_REQUESTED)
            ->sole();

        $this->assertSame('4242', $event->payload['card_last4']);
        $this->assertSame('Visa', $event->payload['card_brand']);
        $this->assertStringNotContainsString(self::CARD, (string) json_encode($event->payload));
        $this->assertStringNotContainsString('987', (string) json_encode($event->payload));
    }

    public function test_card_secrets_are_never_flashed_on_a_validation_error(): void
    {
        $user = $this->makeTrader();

        $this->actingAs($user)
            ->post('/withdrawals', $this->cardPayload(['card_expiry' => '01/20']))
            ->assertRedirect()
            ->assertSessionHasErrors('card_expiry')
            ->assertSessionMissing('_old_input.card_number')
            ->assertSessionMissing('_old_input.card_cvv');

        $this->assertSame(0, WithdrawalRequest::query()->where('user_id', $user->id)->count());
        $this->assertSame(0, WithdrawalCard::query()->where('user_id', $user->id)->count());
    }

    public function test_card_number_with_a_broken_checksum_is_rejected(): void
    {
        $user = $this->makeTrader();

        $this->actingAs($user)
            ->post('/withdrawals', $this->cardPayload(['card_number' => '4242 4242 4242 4241']))
            ->assertSessionHasErrors('card_number');

        $this->assertSame(0, WithdrawalCard::query()->where('user_id', $user->id)->count());
    }

    public function test_expired_card_is_rejected(): void
    {
        $user = $this->makeTrader();

        $this->actingAs($user)
            ->post('/withdrawals', $this->cardPayload(['card_expiry' => now()->subMonth()->format('m/y')]))
            ->assertSessionHasErrors('card_expiry');

        $this->assertSame(0, WithdrawalCard::query()->where('user_id', $user->id)->count());
    }

    public function test_second_request_is_blocked_while_the_first_one_is_open(): void
    {
        $user = $this->makeTrader();

        $this->actingAs($user)->post('/withdrawals', $this->cardPayload())->assertSessionHasNoErrors();

        // A hidden block still submits its inputs, so blank fields pretend to
        // be valid card details. They must not break the second request.
        $this->actingAs($user)
            ->post('/withdrawals', [
                'amount' => 50,
                'method' => WithdrawalRequest::METHOD_CARD,
                'card_id' => (string) WithdrawalCard::query()->where('user_id', $user->id)->value('id'),
                'card_holder' => '',
                'card_number' => '',
                'card_expiry' => '',
                'card_cvv' => '',
            ])
            ->assertSessionHasErrors('withdrawal');

        $this->assertSame(1, WithdrawalRequest::query()->where('user_id', $user->id)->count());
        $this->assertSame(10000.0, $this->balanceOf($user));
    }

    public function test_foreign_card_id_cannot_be_used(): void
    {
        $owner = $this->makeTrader();
        $intruder = $this->makeTrader();

        $card = WithdrawalCard::query()->create([
            'user_id' => $owner->id,
            'holder_name' => 'PETR PETROV',
            'brand' => 'visa',
            'last4' => '4242',
            'expiry' => '12/30',
            'pan_encrypted' => CardCipher::encrypt(self::CARD),
            'fingerprint' => CardCipher::fingerprint(self::CARD),
        ]);

        $this->actingAs($intruder)
            ->post('/withdrawals', [
                'amount' => 100,
                'method' => WithdrawalRequest::METHOD_CARD,
                'card_id' => $card->id,
            ])
            ->assertSessionHasErrors('withdrawal');

        $this->assertSame(0, WithdrawalRequest::query()->where('user_id', $intruder->id)->count());
    }

    public function test_saved_card_is_reused_without_duplicating_the_row(): void
    {
        $user = $this->makeTrader();

        $this->actingAs($user)->post('/withdrawals', $this->cardPayload())->assertSessionHasNoErrors();

        $card = WithdrawalCard::query()->where('user_id', $user->id)->sole();

        // The operator settles the first request in the CRM.
        WithdrawalRequest::query()->where('user_id', $user->id)->update([
            'status' => WithdrawalRequest::STATUS_PAID,
            'is_approved' => true,
            'reviewed_at' => now(),
        ]);

        $this->actingAs($user)
            ->post('/withdrawals', [
                'amount' => 75,
                'method' => WithdrawalRequest::METHOD_CARD,
                'card_id' => $card->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, WithdrawalCard::query()->where('user_id', $user->id)->count());
        $this->assertSame(
            $card->id,
            WithdrawalRequest::query()->where('user_id', $user->id)->latest('id')->value('withdrawal_card_id')
        );
    }

    public function test_crypto_request_requires_a_wallet_and_stores_no_card(): void
    {
        $user = $this->makeTrader();

        $this->actingAs($user)
            ->post('/withdrawals', [
                'amount' => 200,
                'method' => WithdrawalRequest::METHOD_CRYPTO,
            ])
            ->assertSessionHasErrors('wallet_address');

        $this->actingAs($user)
            ->post('/withdrawals', [
                'amount' => 200,
                'method' => WithdrawalRequest::METHOD_CRYPTO,
                'wallet_address' => 'bc1qxy2kgdygjrsqtzq2n0yrf2493p83kkfjhx0wlh',
            ])
            ->assertSessionHasNoErrors();

        $request = WithdrawalRequest::query()->where('user_id', $user->id)->sole();

        $this->assertSame(WithdrawalRequest::METHOD_CRYPTO, $request->method);
        $this->assertNull($request->withdrawal_card_id);
        $this->assertSame('bc1qxy2kgdygjrsqtzq2n0yrf2493p83kkfjhx0wlh', $request->wallet_address);
        $this->assertSame(0, WithdrawalCard::query()->where('user_id', $user->id)->count());
    }

    public function test_amount_above_the_balance_is_rejected(): void
    {
        $user = $this->makeTrader();

        $this->actingAs($user)
            ->post('/withdrawals', $this->cardPayload(['amount' => 999999]))
            ->assertSessionHasErrors('withdrawal');

        $this->assertSame(0, WithdrawalRequest::query()->where('user_id', $user->id)->count());
        $this->assertSame(10000.0, $this->balanceOf($user));
    }

    public function test_dashboard_masks_the_saved_card_number(): void
    {
        $user = $this->makeTrader();

        $this->actingAs($user)->post('/withdrawals', $this->cardPayload())->assertSessionHasNoErrors();

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        $response->assertSee('Заявки на вывод');
        $response->assertSee('4242');
        $response->assertDontSee(self::CARD);
    }

    public function test_dashboard_preselects_the_saved_card_and_hides_the_new_card_block(): void
    {
        $user = $this->makeTrader();

        $this->actingAs($user)->post('/withdrawals', $this->cardPayload())->assertSessionHasNoErrors();

        // The form is only rendered when no request waits for an operator.
        WithdrawalRequest::query()->where('user_id', $user->id)->update([
            'status' => WithdrawalRequest::STATUS_REJECTED,
            'reviewed_at' => now(),
        ]);

        $card = WithdrawalCard::query()->where('user_id', $user->id)->sole();

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        $response->assertSee('id="withdrawal-card-id"', false);
        $response->assertSee('data-preview="withdrawal-card-preview"', false);
        $response->assertSee('data-brand="Visa"', false);
        $response->assertSee('data-masked="•••• •••• •••• 4242"', false);
        $response->assertDontSee(self::CARD);

        // The crypto panel is toggled by the method select, and the new-card
        // block stays hidden while the saved card is selected.
        $response->assertSee('data-method-panel="crypto"', false);

        $html = (string) $response->getContent();

        $this->assertMatchesRegularExpression('/value="'.$card->id.'"[^>]*selected/s', $html);
        $this->assertMatchesRegularExpression('/id="withdrawal-card-preview"\s+hidden/', $html);
        $this->assertMatchesRegularExpression('/id="withdrawal-new-card"\s+hidden/', $html);
    }

    public function test_an_unused_card_is_hidden_after_the_retention_window_and_returns_when_picked_again(): void
    {
        config(['trading.cards.retention_days' => 30]);

        $user = $this->makeTrader();

        $this->actingAs($user)->post('/withdrawals', $this->cardPayload())->assertSessionHasNoErrors();

        WithdrawalRequest::query()->where('user_id', $user->id)->update([
            'status' => WithdrawalRequest::STATUS_REJECTED,
            'review_note' => 'Проверьте номер карты',
            'reviewed_at' => now(),
        ]);

        $card = WithdrawalCard::query()->where('user_id', $user->id)->sole();

        // Hidden from the form, still referenced by the request: the payout
        // history must keep showing the card it was sent to.
        DB::table('withdrawal_cards')->where('id', $card->id)->update([
            'updated_at' => now()->subDays(90),
        ]);

        $withdrawals = app(WithdrawalService::class);

        $this->assertCount(0, $withdrawals->cardsFor($user));

        $this->actingAs($user)->get('/')
            ->assertOk()
            ->assertDontSee('id="withdrawal-card-id"', false)
            ->assertDontSee('data-preview="withdrawal-card-preview"', false)
            ->assertSee('4242')
            ->assertSee('Проверьте номер карты');

        $this->actingAs($user)
            ->post('/withdrawals', [
                'amount' => 75,
                'method' => WithdrawalRequest::METHOD_CARD,
                'card_id' => $card->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertCount(1, $withdrawals->cardsFor($user));
        $this->assertTrue($card->fresh()->updated_at->greaterThan(now()->subDays(30)));
        $this->assertSame(2, WithdrawalRequest::query()->where('user_id', $user->id)->count());
    }

    public function test_the_operator_note_is_shown_next_to_the_form_and_in_the_history(): void
    {
        $user = $this->makeTrader();

        $this->actingAs($user)->post('/withdrawals', $this->cardPayload())->assertSessionHasNoErrors();

        WithdrawalRequest::query()->where('user_id', $user->id)->update([
            'status' => WithdrawalRequest::STATUS_REJECTED,
            'review_note' => 'Имя на карте не совпадает с профилем',
            'reviewed_at' => now(),
        ]);

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        $response->assertSee('Комментарий оператора: Имя на карте не совпадает с профилем');
        $response->assertSee('Комментарий');
    }
}
