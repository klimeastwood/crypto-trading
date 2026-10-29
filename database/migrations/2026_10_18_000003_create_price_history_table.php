<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CoinGecko price ticks. Append-only: Eloquent timestamps are not used.
 *
 * The unique (symbol, recorded_at) index makes the hourly import idempotent
 * and lets MarketDataService upsert overlapping 7-day windows safely.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_history', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('symbol', 16);
            $table->decimal('price', 24, 8);
            $table->dateTime('recorded_at');

            $table->unique(['symbol', 'recorded_at']);
            $table->index('recorded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_history');
    }
};
