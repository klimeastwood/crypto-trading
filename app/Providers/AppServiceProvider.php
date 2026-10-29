<?php

namespace App\Providers;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Console commands that can destroy data. They are refused when the
     * configured database turns out to be the shared Krayin CRM one.
     *
     * @var array<int, string>
     */
    private const DESTRUCTIVE_COMMANDS = [
        'migrate:fresh',
        'migrate:refresh',
        'migrate:reset',
        'db:wipe',
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->guardSharedDatabase();
    }

    /**
     * The trading app shares its database with Krayin CRM. A stray
     * "migrate:fresh" would drop every CRM table, so the destructive
     * commands are blocked with an explicit message instead.
     */
    private function guardSharedDatabase(): void
    {
        if (! config('trading.protect_shared_database')) {
            return;
        }

        if ($this->app->runningUnitTests()) {
            return;
        }

        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            if (! in_array($event->command, self::DESTRUCTIVE_COMMANDS, true)) {
                return;
            }

            if (! $this->databaseContainsCrmTables()) {
                return;
            }

            throw new RuntimeException(
                'Command "'.$event->command.'" is blocked: this application shares the "'
                .$this->databaseName().'" database with Krayin CRM. '
                .'Use "php artisan migrate" (additive migrations only) or disable the guard '
                .'with TRADING_PROTECT_SHARED_DB=false.'
            );
        });
    }

    private function databaseContainsCrmTables(): bool
    {
        try {
            foreach ((array) config('trading.crm_tables', []) as $table) {
                if (Schema::hasTable($table)) {
                    return true;
                }
            }
        } catch (\Throwable) {
            // No connection yet (first install, wrong credentials, ...).
            // The command itself will report the real error.
            return false;
        }

        return false;
    }

    private function databaseName(): string
    {
        return (string) config('database.connections.'.config('database.default').'.database');
    }
}
