<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Virtual balances of CRM users.
 *
 * The "users" table belongs to Krayin CRM and is never modified here: this
 * table only references it, so deleting a user removes their demo account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trading_accounts', function (Blueprint $table) {
            $table->id();

            // Krayin's users.id is an unsigned INT (increments), not a bigint.
            $table->unsignedInteger('user_id')->unique();

            $table->decimal('balance_usd', 24, 8)->default(0);
            $table->timestamps();

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trading_accounts');
    }
};
