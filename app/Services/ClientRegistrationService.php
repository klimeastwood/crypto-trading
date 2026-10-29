<?php

namespace App\Services;

use App\Exceptions\RegistrationException;
use App\Models\TradingAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Self-service registration of a trading client.
 *
 * This is the only place where the trading app writes into Krayin tables: the
 * client needs a CRM user to log in, plus a person and a lead so the call
 * center sees the sign-up inside the sales pipeline. The CRM rows and the
 * trading account are written in one transaction, so a failure never leaves a
 * half-registered client behind.
 *
 * Ids of the CRM dictionaries (role, pipeline, stage, source, type) are
 * resolved at runtime instead of being hardcoded, because the CRM instance is
 * editable by the operators and its seed ids may differ between environments.
 */
class ClientRegistrationService
{
    /**
     * Create an active client account linked to a CRM person and lead.
     *
     * @return User the freshly created CRM user
     *
     * @throws RegistrationException when the shared CRM schema cannot accept a client
     */
    public function register(string $name, string $email, string $password, ?string $phone = null): User
    {
        $this->assertCrmSchema();

        $roleId = $this->clientRoleId();

        return DB::transaction(function () use ($name, $email, $password, $phone, $roleId): User {
            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                // The model's "hashed" cast hashes the plain value.
                'password' => $password,
                'status' => 1,
                'role_id' => $roleId,
                // A client sees only their own records, never the team's.
                'view_permission' => 'individual',
            ]);

            $this->createLead($user, $phone);

            TradingAccount::query()->create([
                'user_id' => $user->id,
                'balance_usd' => (float) config('trading.registration.initial_balance', 0),
            ]);

            return $user;
        });
    }

    /**
     * The CRM tables a client cannot be created without.
     *
     * @throws RegistrationException
     */
    private function assertCrmSchema(): void
    {
        foreach (['users', 'roles', 'persons', 'leads'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RegistrationException('Регистрация временно недоступна: база Krayin CRM не найдена.');
            }
        }
    }

    /**
     * Resolve the CRM role of a trading client.
     *
     * TRADING_CLIENT_ROLE_ID pins an existing role. Otherwise the role named by
     * TRADING_CLIENT_ROLE_NAME is reused, and created on first registration with
     * no permissions at all: the client logs into the CRM, but every section of
     * it stays closed.
     *
     * @throws RegistrationException
     */
    private function clientRoleId(): int
    {
        $configured = (int) config('trading.registration.role_id', 0);

        if ($configured > 0) {
            if (! DB::table('roles')->where('id', $configured)->exists()) {
                throw new RegistrationException('Роль клиента из TRADING_CLIENT_ROLE_ID не найдена в CRM.');
            }

            return $configured;
        }

        $roleName = (string) config('trading.registration.role_name', 'Trading Client');

        $existing = DB::table('roles')->where('name', $roleName)->orderBy('id')->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        $roleId = (int) DB::table('roles')->insertGetId([
            'name' => $roleName,
            'description' => 'Клиент торговой площадки: вход в личный кабинет без доступа к разделам CRM.',
            'permission_type' => 'custom',
            'permissions' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($roleId === 0) {
            throw new RegistrationException('Не удалось создать роль клиента в CRM.');
        }

        return $roleId;
    }

    /**
     * Put the new client into the sales pipeline: a person card and a lead that
     * the operators can call, qualify and convert like any other lead.
     */
    private function createLead(User $user, ?string $phone): void
    {
        $personId = (int) DB::table('persons')->insertGetId([
            'name' => $user->name,
            'emails' => json_encode(
                [['value' => $user->email, 'label' => 'work']],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            ),
            'contact_numbers' => $phone === null
                ? null
                : json_encode([['value' => $phone, 'label' => 'mobile']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $pipeline = $this->defaultPipeline();
        $stage = $pipeline === null ? null : $this->openingStage((int) $pipeline->id);

        if ($pipeline === null) {
            Log::warning('Client registration: в CRM нет воронки, лид создан без воронки и этапа.');
        }

        DB::table('leads')->insert([
            'title' => $user->name.' — регистрация на сайте',
            'description' => $this->leadDescription($user, $phone),
            'lead_value' => 0,
            'status' => 1,
            /**
             * The trading account of the client is looked up by the CRM through
             * `leads.user_id`: the balance block on the card credits that
             * account and the trade feed writes into the timeline of the lead it
             * finds by the same column. The lead therefore belongs to the
             * account that was just created - assigning it to an operator would
             * point both CRM features at the operator's own wallet.
             */
            'user_id' => $user->id,
            'person_id' => $personId,
            'lead_source_id' => $this->dictionaryId('lead_sources', 'Сайт', 'Веб-форма'),
            'lead_type_id' => $this->dictionaryId('lead_types', 'Новый бизнес'),
            'lead_pipeline_id' => $pipeline?->id,
            'lead_pipeline_stage_id' => $stage?->id,
            'expected_close_date' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Notes an operator reads before the first call.
     */
    private function leadDescription(User $user, ?string $phone): string
    {
        $lines = [
            'Клиент зарегистрировался самостоятельно на торговой площадке.',
            'Email: '.$user->email,
        ];

        if ($phone !== null) {
            $lines[] = 'Телефон: '.$phone;
        }

        $lines[] = 'CRM-пользователь: #'.$user->id.'.';

        return implode(PHP_EOL, $lines);
    }

    /**
     * The pipeline a new lead is dropped into; falls back to the first one.
     */
    private function defaultPipeline(): ?object
    {
        if (! Schema::hasTable('lead_pipelines')) {
            return null;
        }

        return DB::table('lead_pipelines')
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
    }

    /**
     * The first ("new") stage of the pipeline, so every sign-up enters the
     * funnel exactly where an operator expects it.
     */
    private function openingStage(int $pipelineId): ?object
    {
        if (! Schema::hasTable('lead_pipeline_stages')) {
            return null;
        }

        return DB::table('lead_pipeline_stages')
            ->where('lead_pipeline_id', $pipelineId)
            ->orderByRaw("case when code = 'new' then 0 else 1 end")
            ->orderBy('id')
            ->first();
    }

    /**
     * Pick a dictionary row by a preferred name, falling back to the first row.
     */
    private function dictionaryId(string $table, string ...$preferredNames): ?int
    {
        if (! Schema::hasTable($table)) {
            return null;
        }

        if ($preferredNames !== []) {
            $preferred = DB::table($table)
                ->whereIn('name', $preferredNames)
                ->orderBy('id')
                ->value('id');

            if ($preferred !== null) {
                return (int) $preferred;
            }
        }

        $fallback = DB::table($table)->orderBy('id')->value('id');

        return $fallback === null ? null : (int) $fallback;
    }
}
