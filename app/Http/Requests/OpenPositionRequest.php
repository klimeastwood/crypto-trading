<?php

namespace App\Http\Requests;

use App\Models\TradingPosition;
use App\Services\TradingService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OpenPositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * The cabinet submits the unit as "usd" or "coin"; an older form (and
     * every API client written before USD sizing) omits it, which the service
     * reads as coins, so no existing request changes meaning.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'size_unit' => strtolower(trim((string) $this->input('size_unit', TradingService::SIZE_UNIT_COIN))),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'symbol' => ['required', 'string', Rule::in(array_keys((array) config('trading.symbols', [])))],
            'side' => ['required', 'string', Rule::in([TradingPosition::SIDE_BUY, TradingPosition::SIDE_SELL])],

            /**
             * `amount` is either a number of coins or a USD value, depending on
             * `size_unit`. The upper bound protects the form from a fat-fingered
             * exponent; the real notional limit is the balance, checked in the
             * service under a lock.
             */
            'amount' => ['required', 'numeric', 'min:0.00000001', 'max:1000000'],

            'size_unit' => ['required', 'string', Rule::in([TradingService::SIZE_UNIT_COIN, TradingService::SIZE_UNIT_USD])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'symbol.required' => 'Выберите инструмент.',
            'symbol.in' => 'Этот инструмент не поддерживается.',
            'side.required' => 'Выберите направление сделки.',
            'side.in' => 'Направление должно быть buy или sell.',
            'amount.required' => 'Укажите размер позиции.',
            'amount.numeric' => 'Размер позиции должен быть числом.',
            'amount.min' => 'Размер позиции должен быть больше нуля.',
            'amount.max' => 'Размер позиции слишком велик.',
            'size_unit.in' => 'Размер позиции указывается в монетах или в долларах.',
            'size_unit.required' => 'Выберите, в чём указан размер позиции.',
        ];
    }
}
