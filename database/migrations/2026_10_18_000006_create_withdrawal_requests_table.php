<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Withdrawal requests a client staged in the cabinet.
 *
 * A request is created as `pending` and never moves money by itself: the
 * balance is untouched until an operator acts. `is_approved` is the flag the
 * CRM sets together with the status, so a query can rely on a single boolean
 * instead of parsing strings. The row is also the public contract the CRM
 * reads from the shared database: user_id -> CRM users.id, withdrawal_card_id
 * -> withdrawal_cards.id, reviewed_by -> CRM users.id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('withdrawal_requests', function (Blueprint $table) {
            $table->id();

            $table->unsignedInteger('user_id');

            // Null for a crypto payout, or when the card was deleted later.
            $table->unsignedBigInteger('withdrawal_card_id')->nullable();

            $table->decimal('amount_usd', 24, 8);

            // card | crypto — where the money should be sent.
            $table->string('method', 16)->default('card');

            $table->string('wallet_address', 191)->nullable();

            // pending | approved | rejected | paid
            $table->string('status', 16)->default('pending');

            $table->boolean('is_approved')->default(false);

            // The CRM operator who reviewed the request and when.
            $table->unsignedInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 255)->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'created_at']);

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();

            $table->foreign('withdrawal_card_id')
                ->references('id')
                ->on('withdrawal_cards')
                ->nullOnDelete();

            $table->foreign('reviewed_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawal_requests');
    }
};
