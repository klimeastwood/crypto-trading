<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only feed of client actions, shared with Krayin CRM.
 *
 * The CRM reads this table directly from the same database, so it is a
 * public contract: user_id -> CRM users.id, event_type is a stable string
 * and payload holds the details as JSON. Only created_at is stored,
 * because an event is never updated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trading_events', function (Blueprint $table) {
            $table->id();

            // Krayin's users.id is an unsigned INT (increments), not a bigint.
            $table->unsignedInteger('user_id');

            $table->string('event_type', 64);
            $table->json('payload');

            // Append-only: the model never sets updated_at.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index(['event_type', 'created_at']);

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trading_events');
    }
};
