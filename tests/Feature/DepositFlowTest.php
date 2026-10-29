<?php

namespace Tests\Feature;

use App\Models\DepositCard;
use App\Models\DepositRequest;
use App\Models\TradingAccount;
use App\Models\TradingEvent;
use App\Models\User;
use App\Services\DepositService;
use App\Services\TradingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The deposit flow of the client cabinet.
 *
 * A deposit used to be credited on submit. These tests pin the new rule: the
 * client stages a request with a card, the balance does not move, and only the
 * CRM approval (the bridge endpoint) credits it - exactly once per request.
 */
class DepositFlowTest extends TestCase
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

        if (! DB::getSchemaBuilder()->hasTable('deposit_requests')) {
            $this->markTestSkipped('Run "php artisan migrate" before the deposit tests.');
        }
    }

    private function makeTrader(): User
    {
        $roleId = DB::table('roles')->insertGetId([
            'name' => 'Deposit test',
            'description' => 'Temporary role created by DepositFlowTest',
            'permission_type' => 'all',
            'permissions' => json_encode(['leads' => ['create']]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::factory()->create([
            'role_id' => $roleId,
            'status' => 1,
        ]);

        // The cabinet always shows an account, so the tests start from the
        // configured starting balance instead of "no row yet".
        app(TradingService::class)->accountFor($user);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function cardPayload(array $overrides = []): array
    {
        return array_merge([
            'amount' => 100,
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

    public function test_card_request_stores_the_encrypted_card_and_leaves_the_balance_untouched(): void
    {
        $user = $this->makeTrader();
        $initial = $this->balanceOf($user);

        $response = $this->actingAs($user)
            ->post('/deposits', $this->cardPayload());

        $response->assertRedirect()->assertSessionHasNoErrors();

        $request = DepositRequest::query()->where('user_id', $user->id)->sole();

        $this->assertSame(DepositRequest::STATUS_PENDING, $request->status);
        $this->assertFalse($request->is_approved);
        $this->assertSame('100.00000000', (string) $request->amount_usd);

        // The whole point of the change: nothing is credited before approval.
        $this->assertSame($initial, $this->balanceOf($user));

        $card = DepositCard::query()->where('user_id', $user->id)->sole();

        $this->assertSame('4242', $card->last4);
        $this->assertSame('visa', $card->brand);
        $this->assertSame(self::CARD, $card->pan());
        $this->assertNotSame(self::CARD, $card->pan_encrypted);
        $this->assertSame($card->id, $request->deposit_card_id);

        // The CVV lives on the request alone - the CRM desk reads it there - and
        // never on the card row, in the event payload or in the session.
        $this->assertSame('987', $request->card_cvv);

        $row = (string) json_encode(DB::table('deposit_cards')->where('user_id', $user->id)->first());
        $this->assertStringNotContainsString('987', $row);
        $this->assertStringNotContainsString(self::CARD, $row);
        $response->assertSessionMissing('_old_input');

        $event = TradingEvent::query()
            ->where('user_id', $user->id)
            ->where('event_type', TradingEvent::TYPE_DEPOSIT_REQUESTED)
            ->sole();

        $this->assertSame($request->id, $event->payload['request_id']);
        $this->assertSame('4242', $event->payload['card_last4']);
        $this->assertSame('Visa', $event->payload['card_brand']);
        $this->assertStringNotContainsString(self::CARD, (string) json_encode($event->payload));
        $this->assertStringNotContainsString('987', (string) json_encode($event->payload));
    }

    public function test_card_secrets_are_never_flashed_on_a_validation_error(): void
    {
        $user = $this->makeTrader();

        $this->actingAs($user)
            ->post('/deposits', $this->cardPayload(['card_expiry' => '01/20']))
            ->assertRedirect()
            ->assertSessionHasErrors('card_expiry')
            ->assertSessionMissing('_old_input.card_number')
            ->assertSessionMissing('_old_input.card_cvv');

        $this->assertSame(0, DepositRequest::query()->where('user_id', $user->id)->count());
        $this->assertSame(0, DepositCard::query()->where('user_id', $user->id)->count());
    }

    public function test_card_number_with_a_broken_checksum_is_rejected(): void
    {
        $user = $this->makeTrader();

        $this->actingAs($user)
            ->post('/deposits', $this->cardPayload(['card_number' => '4242 4242 4242 4241']))
            ->assertSessionHasErrors('card_number');

        $this->assertSame(0, DepositCard::query()->where('user_id', $user->id)->count());
        $this->assertSame(0, DepositRequest::query()->where('user_id', $user->id)->count());
    }

    public function test_second_request_is_blocked_while_the_first_one_is_pending(): void
    {
        $user = $this->makeTrader();

        $this->actingAs($user)->post('/deposits', $this->cardPayload())->assertSessionHasNoErrors();

        // A hidden block still submits its inputs, so blank fields pretend to
        // be valid card details. They must not break the second request.
        $this->actingAs($user)
            ->post('/deposits', [
                'amount' => 50,
                'card_id' => (string) DepositCard::query()->where('user_id', $user->id)->value('id'),
                'card_holder' => '',
                'card_number' => '',
                'card_expiry' => '',
                'card_cvv' => '',
            ])
            ->assertSessionHasErrors('deposit');

        $this->assertSame(1, DepositRequest::query()->where('user_id', $user->id)->count());
    }

    public function test_an_approved_request_is_credited_once_per_reference(): void
    {
        $user = $this->makeTrader();
        $initial = $this->balanceOf($user);

        $trading = app(TradingService::class);

        $first = $trading->creditDeposit($user, 100.0, 'deposit:42');
        $second = $trading->creditDeposit($user, 100.0, 'deposit:42');

        $this->assertTrue($first['applied']);
        $this->assertFalse($second['applied']);
        $this->assertSame($initial + 100.0, $this->balanceOf($user));

        $event = TradingEvent::query()
            ->where('user_id', $user->id)
            ->where('event_type', TradingEvent::TYPE_DEPOSIT)
            ->sole();

        $this->assertSame('deposit:42', $event->payload['reference']);
        $this->assertSame($initial, (float) $event->payload['balance_before']);
        $this->assertSame($initial + 100.0, (float) $event->payload['balance_after']);
    }

    public function test_the_bridge_credits_a_deposit_but_refuses_a_bad_token(): void
    {
        config(['trading.bridge.token' => 'bridge-test-token']);

        $user = $this->makeTrader();
        $initial = $this->balanceOf($user);

        $this->postJson('/api/deposits/credit', [
            'user_id' => $user->id,
            'amount' => 250,
            'reference' => 'deposit:7',
        ])->assertStatus(401);

        $response = $this->postJson('/api/deposits/credit', [
            'user_id' => $user->id,
            'amount' => 250,
            'reference' => 'deposit:7',
        ], ['Authorization' => 'Bearer bridge-test-token'])
            ->assertOk()
            ->assertJsonPath('applied', true);

        $this->assertSame($initial + 250.0, (float) $response->json('balance_after'));

        $this->assertSame($initial + 250.0, $this->balanceOf($user));

        // A retry with the same reference reports the first delivery instead of
        // crediting the request a second time.
        $this->postJson('/api/deposits/credit', [
            'user_id' => $user->id,
            'amount' => 250,
            'reference' => 'deposit:7',
        ], ['Authorization' => 'Bearer bridge-test-token'])
            ->assertOk()
            ->assertJsonPath('applied', false);

        $this->assertSame($initial + 250.0, $this->balanceOf($user));
    }

    public function test_dashboard_preselects_the_saved_card_and_fills_its_preview(): void
    {
        $user = $this->makeTrader();

        $this->actingAs($user)->post('/deposits', $this->cardPayload())->assertSessionHasNoErrors();

        // The form is only rendered when no request waits for an operator.
        DepositRequest::query()->where('user_id', $user->id)->update([
            'status' => DepositRequest::STATUS_REJECTED,
            'reviewed_at' => now(),
        ]);

        $card = DepositCard::query()->where('user_id', $user->id)->sole();

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        $response->assertSee('id="deposit-card-id"', false);
        $response->assertSee('data-card-select', false);
        $response->assertSee('data-preview="deposit-card-preview"', false);
        $response->assertSee('data-brand="Visa"', false);
        $response->assertSee('data-masked="•••• •••• •••• 4242"', false);

        // Neither the full number nor a second copy of it has any business on
        // the page: the select and the preview carry the last four digits.
        $response->assertDontSee(self::CARD);

        $html = (string) $response->getContent();

        // The newest card is selected, so the new-card block with its number
        // and CVV inputs stays hidden; the preview starts empty and the script
        // fills it from the selected option.
        $this->assertMatchesRegularExpression('/value="'.$card->id.'"[^>]*selected/s', $html);
        $this->assertMatchesRegularExpression('/id="deposit-card-preview"\s+hidden/', $html);
        $this->assertMatchesRegularExpression('/id="deposit-new-card"\s+hidden/', $html);
    }

    public function test_an_unused_card_is_hidden_after_the_retention_window_and_returns_when_picked_again(): void
    {
        config(['trading.cards.retention_days' => 30]);

        $user = $this->makeTrader();

        $this->actingAs($user)->post('/deposits', $this->cardPayload())->assertSessionHasNoErrors();

        DepositRequest::query()->where('user_id', $user->id)->update([
            'status' => DepositRequest::STATUS_REJECTED,
            'review_note' => 'Не нашли поступление',
            'reviewed_at' => now(),
        ]);

        $card = DepositCard::query()->where('user_id', $user->id)->sole();

        // The form forgets a card after 30 quiet days; the row itself and the
        // request that points at it stay, so the history is not rewritten.
        DB::table('deposit_cards')->where('id', $card->id)->update([
            'updated_at' => now()->subDays(90),
        ]);

        $deposits = app(DepositService::class);

        $this->assertCount(0, $deposits->cardsFor($user));

        $this->actingAs($user)->get('/')
            ->assertOk()
            ->assertDontSee('id="deposit-card-id"', false)
            ->assertDontSee('data-preview="deposit-card-preview"', false)
            ->assertSee('4242')
            ->assertSee('Не нашли поступление');

        // Picking the hidden card by id still works and counts as a use.
        $this->actingAs($user)
            ->post('/deposits', [
                'amount' => 25,
                'card_id' => $card->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertCount(1, $deposits->cardsFor($user));
        $this->assertTrue($card->fresh()->updated_at->greaterThan(now()->subDays(30)));
        $this->assertSame(2, DepositRequest::query()->where('user_id', $user->id)->count());
    }

    public function test_the_operator_note_is_shown_next_to_the_form_and_in_the_history(): void
    {
        $user = $this->makeTrader();

        $this->actingAs($user)->post('/deposits', $this->cardPayload())->assertSessionHasNoErrors();

        DepositRequest::query()->where('user_id', $user->id)->update([
            'status' => DepositRequest::STATUS_REJECTED,
            'review_note' => 'Поступление не найдено, проверьте выписку',
            'reviewed_at' => now(),
        ]);

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        $response->assertSee('Комментарий оператора: Поступление не найдено, проверьте выписку');
        $response->assertSee('Комментарий');
    }
}
