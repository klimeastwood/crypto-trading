<?php

namespace App\Http\Controllers;

use App\Exceptions\TradingException;
use App\Http\Requests\StoreDepositRequest;
use App\Services\DepositService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;

/**
 * Staging of a deposit request from the cabinet.
 *
 * The action only records the request: the balance is not credited and the
 * client sees "На проверке" until an operator confirms the money in the CRM.
 */
class DepositController extends Controller
{
    public function __construct(private readonly DepositService $deposits) {}

    public function store(StoreDepositRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        try {
            // The service needs the number once to encrypt it; it is never
            // written in the clear, never logged and never returned.
            $this->deposits->request($request->user(), $validated);
        } catch (TradingException $exception) {
            // A failed request must not bounce the card number or the CVV
            // back into the session; the client retypes them.
            return back()
                ->withErrors(['deposit' => $exception->getMessage()])
                ->withInput(Arr::except($validated, ['card_number', 'card_cvv']));
        }

        return back()->with(
            'status',
            'Заявка на пополнение отправлена. Оператор проверит поступление и подтвердит зачисление.'
        );
    }
}
