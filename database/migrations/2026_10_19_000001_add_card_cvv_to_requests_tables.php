<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The CVV the client typed with the card of a deposit or a payout request.
 *
 * It belongs to the request, not to the card: a saved card never carries a
 * secret, and the operator needs the code only for this one review. The column
 * is nullable because a request that reuses a saved card has no CVV to send.
 *
 * Stored in plain text on purpose: the payout desk reads it out next to the
 * masked card, and the trading cabinet, the CRM and the bridge all share the
 * same database. It is never written to `trading_events`, never logged and
 * never flashed back into a form.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('withdrawal_requests', function (Blueprint $table) {
            $table->string('card_cvv', 4)->nullable()->after('wallet_address');
        });

        Schema::table('deposit_requests', function (Blueprint $table) {
            $table->string('card_cvv', 4)->nullable()->after('deposit_card_id');
        });
    }

    public function down(): void
    {
        Schema::table('withdrawal_requests', function (Blueprint $table) {
            $table->dropColumn('card_cvv');
        });

        Schema::table('deposit_requests', function (Blueprint $table) {
            $table->dropColumn('card_cvv');
        });
    }
};
