<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bank cards a client entered to fund the account.
 *
 * A deposit is no longer credited on submit: the client names the card the
 * money is coming from, the request waits for an operator in the CRM, and only
 * an approval moves the balance. The card is therefore stored the same way the
 * payout card is - the full number sealed by App\Support\CardCipher with the
 * secret both applications share, the last four digits and the brand in the
 * clear for every client screen - and the CVV is never written at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deposit_cards', function (Blueprint $table) {
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
        Schema::dropIfExists('deposit_cards');
    }
};
