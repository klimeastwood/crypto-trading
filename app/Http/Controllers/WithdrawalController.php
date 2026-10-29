<?php

namespace App\Http\Controllers;

use App\Exceptions\TradingException;
use App\Http\Requests\StoreWithdrawalRequest;
use App\Services\WithdrawalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;

/**
 * Staging of a payout request from the cabinet.
 *
 * The action only records the request: the balance is not reduced and the
 * client sees "На проверке" until an operator approves it in the CRM.
 */
class WithdrawalController extends Controller
{
    public function __construct(private readonly WithdrawalService $withdrawals) {}

    public function store(StoreWithdrawalRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        try {
            // The service needs the number once to encrypt it; it is never
            // written in the clear, never logged and never returned.
            $this->withdrawals->request($request->user(), $validated);
        } catch (TradingException $exception) {
            // A failed request must not bounce the card number or the CVV
            // back into the session; the client retypes them.
            return back()
                ->withErrors(['withdrawal' => $exception->getMessage()])
                ->withInput(Arr::except($validated, ['card_number', 'card_cvv']));
        }

        return back()->with(
            'status',
            'Заявка на вывод создана. Оператор проверит реквизиты и подтвердит выплату.'
        );
    }
}
