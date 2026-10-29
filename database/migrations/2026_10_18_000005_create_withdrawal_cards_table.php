<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bank cards a client entered for a withdrawal.
 *
 * The full number is stored because the payout operator has to see it, but it
 * is never stored in the clear: `pan_encrypted` holds an AES-256-GCM payload
 * built by App\Support\CardCipher, and the client screens only ever read the
 * masked value built from `last4`. The CVV is not stored at all — it is
 * validated and dropped inside the same request that creates the row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('withdrawal_cards', function (Blueprint $table) {
            $table->id();

            // Krayin's users.id is an unsigned INT (increments), not a bigint.
            $table->unsignedInteger('user_id');

            $table->string('holder_name', 120);

            // visa / mastercard / mir / unknown — detected from the number.
            $table->string('brand', 16)->default('unknown');

            $table->string('last4', 4);
            $table->string('expiry', 5);

            // Base64(iv | tag | ciphertext) of the full card number.
            $table->text('pan_encrypted');

            // sha256 of the digits: lets the app reuse the same card instead of
            // storing it twice, without keeping a searchable copy of the number.
            $table->string('fingerprint', 64);

            $table->timestamps();

            $table->unique(['user_id', 'fingerprint']);
            $table->index('user_id');

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawal_cards');
    }
};
