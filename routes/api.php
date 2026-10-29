<?php

use App\Http\Controllers\BridgeBalanceController;
use App\Http\Middleware\AuthenticateBridge;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| CRM bridge
|--------------------------------------------------------------------------
|
| Server-to-server endpoints. No session, no CSRF: the caller is Krayin CRM
| and proves itself with the shared Bearer token.
|
*/

Route::middleware(AuthenticateBridge::class)->group(function () {
    Route::post('/balance/adjust', [BridgeBalanceController::class, 'adjust'])
        ->name('api.balance.adjust');

    /**
     * Credits a deposit the operator approved in the CRM. The caller sends the
     * deposit request id as `reference`, so a retry cannot credit it twice.
     */
    Route::post('/deposits/credit', [BridgeBalanceController::class, 'deposit'])
        ->name('api.deposits.credit');

    /**
     * Records the approve/reject decision an operator made on a payout. The
     * payout itself stays with the back office; the client cabinet reads the
     * new status from this table.
     */
    Route::post('/withdrawals/review', [BridgeBalanceController::class, 'withdrawal'])
        ->name('api.withdrawals.review');
});
