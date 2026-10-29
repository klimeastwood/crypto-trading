<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Supported instruments
    |--------------------------------------------------------------------------
    |
    | Internal symbol => CoinGecko coin id. Add new pairs here and both the
    | dashboard, the chart and the "trading:fetch-prices" command will pick
    | them up automatically.
    |
    */

    'symbols' => [
        'BTC' => 'bitcoin',
        'ETH' => 'ethereum',
        'SOL' => 'solana',
        'BNB' => 'binancecoin',
        'XRP' => 'ripple',
    ],

    'default_symbol' => env('TRADING_DEFAULT_SYMBOL', 'BTC'),

    'vs_currency' => env('TRADING_VS_CURRENCY', 'usd'),

    'history_days' => (int) env('TRADING_HISTORY_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | Position sizing
    |--------------------------------------------------------------------------
    |
    | The cabinet lets a client size a position either in coins or in USD. A
    | USD amount is converted with the latest stored price at the moment the
    | position is opened, so the trade is always priced exactly as the balance
    | is debited. `min_notional` is the smallest position in USD: below it the
    | exchange fees would eat the whole result.
    |
    */

    'position' => [
        'min_notional' => (float) env('TRADING_POSITION_MIN_USD', 0.01),
    ],

    /*
    |--------------------------------------------------------------------------
    | Saved cards
    |--------------------------------------------------------------------------
    |
    | A client accumulates one row per card they were funded from or paid out
    | to. `retention_days` hides the cards that have not been used for that
    | long from the cabinet without deleting them: every request keeps its
    | `card_id`, so an operator still sees the card a past payment used. Zero
    | keeps every saved card visible, which is the default.
    |
    */

    'cards' => [
        'retention_days' => (int) env('TRADING_CARD_HISTORY_DAYS', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Starting balance
    |--------------------------------------------------------------------------
    |
    | Balance created for an existing CRM user the first time they open the
    | dashboard. Clients who register themselves get
    | `registration.initial_balance` instead. Both default to zero, so nobody
    | can trade on money the platform handed out - an operator funds the
    | account from the CRM lead card.
    |
    */

    'initial_balance' => (float) env('TRADING_INITIAL_BALANCE', 0),

    /*
    |--------------------------------------------------------------------------
    | Client self-registration
    |--------------------------------------------------------------------------
    |
    | A guest who signs up on the public /register page becomes an active CRM
    | user plus a CRM person and lead, so the call center sees the sign-up in
    | the sales pipeline. This is the only flow that inserts into Krayin tables
    | (users, roles, persons, leads) - trading data stays in trading tables.
    |
    | `role_id` pins an existing CRM role (0 = reuse or create `role_name`,
    | which has no permissions at all). The lead is owned by the account that was
    | just registered, because the CRM reads `leads.user_id` both as the owner of
    | the trading account it may credit and as the key of the trade timeline.
    |
    */

    'registration' => [
        'initial_balance' => (float) env('TRADING_REGISTRATION_INITIAL_BALANCE', 0),
        'role_id' => (int) env('TRADING_CLIENT_ROLE_ID', 0),
        'role_name' => env('TRADING_CLIENT_ROLE_NAME', 'Trading Client'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Withdrawals
    |--------------------------------------------------------------------------
    |
    | A client stages a withdrawal request and an operator approves it in the
    | CRM; no money moves until that approval. The minimum keeps micro-payouts
    | out of the operator queue, the maximum matches the bridge limit so a
    | single client cannot queue an amount no operator is allowed to settle.
    |
    */

    'withdrawal' => [
        'min_amount' => (float) env('TRADING_WITHDRAWAL_MIN_AMOUNT', 10),
        'max_amount' => (float) env('TRADING_WITHDRAWAL_MAX_AMOUNT', 1000000),

        /**
         * Secret both applications use for the card number.
         *
         * The CRM pays the request out and decrypts `pan_encrypted`, but it
         * runs on its own APP_KEY, so the number is sealed with this value
         * instead: set the same WITHDRAWAL_CARD_KEY on both sides. An empty key
         * falls back to APP_KEY, which the CRM cannot read - the payout
         * operator then sees the last four digits only. A deposit card is
         * sealed with the same secret, because the CRM opens it on the review
         * screen for exactly the same reason.
         */
        'card_key' => env('WITHDRAWAL_CARD_KEY', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Deposits
    |--------------------------------------------------------------------------
    |
    | A deposit is not credited on submit any more. The client names the card
    | the money is coming from and an operator confirms the transfer in the CRM;
    | only then does the balance grow. The minimum keeps a mistyped amount out of
    | the operator queue, the maximum matches the bridge limit so a single
    | request cannot exceed what an operator may approve.
    |
    */

    'deposit' => [
        'min_amount' => (float) env('TRADING_DEPOSIT_MIN_AMOUNT', 10),
        'max_amount' => (float) env('TRADING_DEPOSIT_MAX_AMOUNT', 1000000),
    ],

    /*
    |--------------------------------------------------------------------------
    | CRM bridge
    |--------------------------------------------------------------------------
    |
    | Krayin CRM adjusts a trader balance over HTTP instead of writing into the
    | shared tables. The token is sent as a Bearer token and must match
    | TRADING_BRIDGE_TOKEN in both applications. An empty token disables the
    | endpoint with a 503, so a deployment cannot expose it by accident.
    |
    */

    'bridge' => [
        'token' => env('TRADING_BRIDGE_TOKEN', ''),
        'max_amount' => (float) env('TRADING_BRIDGE_MAX_AMOUNT', 1000000),
    ],

    'api' => [
        'base_url' => env('COINGECKO_BASE_URL', 'https://api.coingecko.com/api/v3'),
        'timeout' => (int) env('COINGECKO_TIMEOUT', 15),
        'retry_times' => (int) env('COINGECKO_RETRY_TIMES', 2),
        'retry_delay_ms' => (int) env('COINGECKO_RETRY_DELAY_MS', 1500),
    ],

    /*
    |--------------------------------------------------------------------------
    | Shared database safety
    |--------------------------------------------------------------------------
    |
    | The trading app lives in the same database as Krayin CRM. The guard
    | blocks destructive artisan commands (migrate:fresh, db:wipe, ...) when
    | CRM tables are detected, so trading work can never wipe the CRM data.
    |
    */

    'protect_shared_database' => (bool) env('TRADING_PROTECT_SHARED_DB', true),

    /*
    |--------------------------------------------------------------------------
    | Krayin CRM fingerprints
    |--------------------------------------------------------------------------
    |
    | Used by the shared-database guard to recognise the CRM schema. If at
    | least one of these tables exists, destructive commands are refused.
    |
    */

    'crm_tables' => [
        'contacts',
        'leads',
        'roles',
        'activities',
    ],

];
