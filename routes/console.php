<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console routes
|--------------------------------------------------------------------------
|
| The free CoinGecko API is called hourly so the dashboard always has
| fresh 7-day history. Run "php artisan schedule:work" (or a cron entry)
| to enable the scheduler.
|
*/

Schedule::command('trading:fetch-prices')
    ->hourly()
    ->withoutOverlapping();
