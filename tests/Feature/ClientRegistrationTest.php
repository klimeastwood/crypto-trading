<?php

namespace Tests\Feature;

use App\Models\TradingAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Public self-registration of a trading client.
 *
 * The tests run against the shared krayin_crm schema, so they assert both sides
 * of the flow: the trading account in the trading tables and the person/lead
 * pair the call center receives in the CRM. DatabaseTransactions rolls every
 * row back, no CRM table is truncated.
 */
class ClientRegistrationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['users', 'roles', 'persons', 'leads'] as $table) {
            if (! DB::getSchemaBuilder()->hasTable($table)) {
                $this->markTestSkipped('CRM schema with the '.$table.' table is required.');
            }
        }

        if (! DB::getSchemaBuilder()->hasTable('trading_accounts')) {
            $this->markTestSkipped('Run "php artisan migrate" before the registration tests.');
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Иван Петров',
            'email' => 'ivan.petrov@example.com',
            'phone' => '+375 29 123-45-67',
            'password' => 'Secret12345',
            'password_confirmation' => 'Secret12345',
            'terms' => '1',
        ], $overrides);
    }

    private function makeRole(string $name = 'Registration test role'): int
    {
        return (int) DB::table('roles')->insertGetId([
            'name' => $name,
            'description' => 'Created by ClientRegistrationTest.',
            'permission_type' => 'custom',
            'permissions' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * An authenticated CRM account: the guest pages must redirect it away.
     */
    private function makeOperator(): User
    {
        return User::query()->create([
            'name' => 'Оператор Тест',
            'email' => 'operator.'.uniqid().'@example.com',
            'password' => 'Secret12345',
            'status' => 1,
            'role_id' => $this->makeRole(),
            'view_permission' => 'global',
        ]);
    }

    public function test_guests_see_the_registration_form(): void
    {
        $this->get('/register')
            ->assertOk()
            ->assertSee('Создать аккаунт')
            ->assertSee('action="'.route('register').'"', false)
            ->assertSee('name="password_confirmation"', false);
    }

    public function test_authenticated_users_are_redirected_away_from_the_form(): void
    {
        $this->actingAs($this->makeOperator())
            ->get('/register')
            ->assertRedirect('/');
    }

    public function test_a_guest_registers_and_receives_a_crm_lead(): void
    {
        $this->post('/register', $this->payload())
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('status');

        $user = User::query()->where('email', 'ivan.petrov@example.com')->sole();

        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->status);
        $this->assertTrue(Hash::check('Secret12345', $user->password));

        $stored = DB::table('users')->where('id', $user->id)->first();

        $this->assertSame('individual', $stored->view_permission);
        $this->assertSame('Trading Client', DB::table('roles')->where('id', $stored->role_id)->value('name'));
        $this->assertSame('custom', DB::table('roles')->where('id', $stored->role_id)->value('permission_type'));

        $person = DB::table('persons')->where('name', 'Иван Петров')->orderByDesc('id')->first();

        $this->assertNotNull($person);
        $this->assertSame(
            'ivan.petrov@example.com',
            data_get(json_decode((string) $person->emails, true), '0.value'),
        );
        $this->assertSame(
            '+375 29 123-45-67',
            data_get(json_decode((string) $person->contact_numbers, true), '0.value'),
        );

        $lead = DB::table('leads')->where('person_id', $person->id)->sole();

        $this->assertSame('Иван Петров — регистрация на сайте', $lead->title);
        $this->assertSame(1, (int) $lead->status);
        $this->assertSame($user->id, (int) $lead->user_id);
        $this->assertStringContainsString('ivan.petrov@example.com', (string) $lead->description);

        $pipeline = DB::table('lead_pipelines')->orderByDesc('is_default')->orderBy('id')->first();

        $this->assertNotNull($pipeline);
        $this->assertSame((int) $pipeline->id, (int) $lead->lead_pipeline_id);
        $this->assertSame(
            'new',
            DB::table('lead_pipeline_stages')->where('id', $lead->lead_pipeline_stage_id)->value('code'),
        );
        $this->assertNotNull($lead->lead_source_id);
        $this->assertNotNull($lead->lead_type_id);

        $this->assertSame(
            0.0,
            (float) TradingAccount::query()->where('user_id', $user->id)->value('balance_usd'),
        );
    }

    public function test_the_new_client_lands_on_a_zero_balance_dashboard(): void
    {
        $this->followingRedirects()
            ->post('/register', $this->payload())
            ->assertOk()
            ->assertSee('Добро пожаловать, Иван Петров!')
            ->assertSee('$0.00')
            ->assertSee('Торговый счёт пуст');
    }

    public function test_the_lead_belongs_to_the_registered_client(): void
    {
        $this->post('/register', $this->payload())->assertSessionHasNoErrors();

        $user = User::query()->where('email', 'ivan.petrov@example.com')->sole();
        $person = DB::table('persons')->where('name', 'Иван Петров')->sole();
        $lead = DB::table('leads')->where('person_id', $person->id)->sole();

        /**
         * The CRM resolves the trading account of a lead through `leads.user_id`
         * (the balance block credits it, the event feed writes into its
         * timeline), so the lead must stay with the client's own account.
         */
        $this->assertSame($user->id, (int) $lead->user_id);
        $this->assertNotNull(
            DB::table('trading_accounts')->where('user_id', $lead->user_id)->value('id'),
        );
    }

    public function test_the_client_role_is_reused_instead_of_duplicated(): void
    {
        /**
         * A real installation may already carry the role (the first client who
         * registered created it), so the test starts from whatever the database
         * holds and only creates the role when the name is free.
         */
        $roleId = DB::table('roles')->where('name', 'Trading Client')->orderBy('id')->value('id');

        if ($roleId === null) {
            $roleId = $this->makeRole('Trading Client');
        }

        $this->post('/register', $this->payload())->assertSessionHasNoErrors();

        $user = User::query()->where('email', 'ivan.petrov@example.com')->sole();

        $this->assertSame((int) $roleId, (int) $user->role_id);
        $this->assertSame(1, DB::table('roles')->where('name', 'Trading Client')->count());
    }

    public function test_email_is_stored_in_lower_case(): void
    {
        $this->post('/register', $this->payload(['email' => 'Mixed.Case@Example.COM']))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, User::query()->where('email', 'mixed.case@example.com')->count());
    }

    public function test_a_duplicate_email_creates_no_crm_records(): void
    {
        User::query()->create([
            'name' => 'Существующий Клиент',
            'email' => 'ivan.petrov@example.com',
            'password' => 'Secret12345',
            'status' => 1,
            'role_id' => $this->makeRole(),
            'view_permission' => 'individual',
        ]);

        $personsBefore = DB::table('persons')->count();
        $leadsBefore = DB::table('leads')->count();

        $this->from('/register')
            ->post('/register', $this->payload(['email' => 'IVAN.PETROV@Example.com']))
            ->assertRedirect('/register')
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertSame($personsBefore, DB::table('persons')->count());
        $this->assertSame($leadsBefore, DB::table('leads')->count());
    }

    public function test_registration_validates_the_password_the_terms_and_the_email_format(): void
    {
        $this->from('/register')
            ->post('/register', $this->payload(['password' => 'short', 'password_confirmation' => 'short']))
            ->assertSessionHasErrors('password');

        $this->from('/register')
            ->post('/register', $this->payload(['password_confirmation' => 'Other12345']))
            ->assertSessionHasErrors('password');

        $this->from('/register')
            ->post('/register', $this->payload(['terms' => null]))
            ->assertSessionHasErrors('terms');

        $this->from('/register')
            ->post('/register', $this->payload(['email' => 'not-an-email']))
            ->assertSessionHasErrors('email');

        $this->assertSame(0, DB::table('persons')->where('name', 'Иван Петров')->count());
    }
}
