<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepositController;
use App\Http\Controllers\WithdrawalController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:5,1');

    Route::get('/register', [RegisterController::class, 'create'])->name('register');
    Route::post('/register', [RegisterController::class, 'store'])->middleware('throttle:5,1');
});

Route::post('/logout', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    /*
     * Deposits are not credited on submit any more: the client stages a request
     * with the card the money comes from and an operator approves it in the CRM.
     * The old `POST /deposit` path is kept as an alias of the new one, so a
     * bookmark of the former form lands on the same staging action instead of a
     * 404 - and without a card it is refused.
     */
    Route::post('/deposits', [DepositController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('deposits.store');

    Route::post('/deposit', [DepositController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('deposit');

    Route::post('/positions', [DashboardController::class, 'openPosition'])->name('positions.store');
    Route::post('/positions/{position}/close', [DashboardController::class, 'closePosition'])->name('positions.close');

    Route::post('/withdrawals', [WithdrawalController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('withdrawals.store');
});
