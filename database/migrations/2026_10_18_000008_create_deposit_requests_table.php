<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deposit requests a client staged in the cabinet.
 *
 * A request is created as `pending` and never moves money by itself: the client
 * has named the card the money comes from, the CRM operator checks that the
 * funds arrived and only then credits the balance. `is_approved` is the flag the
 * CRM sets together with the status, so a query can rely on a single boolean
 * instead of parsing strings. The row is the public contract between the two
 * applications: user_id -> CRM users.id, deposit_card_id -> deposit_cards.id,
 * reviewed_by -> CRM users.id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deposit_requests', function (Blueprint $table) {
            $table->id();

            $table->unsignedInteger('user_id');

            // Null only when the card row was deleted after the request.
            $table->unsignedBigInteger('deposit_card_id')->nullable();

            $table->decimal('amount_usd', 24, 8);

            // pending | approved | rejected
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

            $table->foreign('deposit_card_id')
                ->references('id')
                ->on('deposit_cards')
                ->nullOnDelete();

            $table->foreign('reviewed_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deposit_requests');
    }
};
