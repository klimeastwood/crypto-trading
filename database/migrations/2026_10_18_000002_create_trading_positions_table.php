<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trading_positions', function (Blueprint $table) {
            $table->id();

            // Krayin's users.id is an unsigned INT (increments), not a bigint.
            $table->unsignedInteger('user_id');

            $table->string('symbol', 16);
            $table->string('side', 8);
            $table->decimal('amount', 24, 8);
            $table->decimal('entry_price', 24, 8);
            $table->decimal('close_price', 24, 8)->nullable();
            $table->string('status', 16)->default('open');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();

            $table->index(['user_id', 'status']);
            $table->index(['symbol', 'status']);
            $table->index('closed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trading_positions');
    }
};
