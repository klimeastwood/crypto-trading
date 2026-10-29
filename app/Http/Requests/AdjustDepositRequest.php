<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Payload sent by Krayin CRM when an operator approves a deposit request.
 *
 * `reference` is the CRM idempotency key (`deposit:{id}`). It is required on
 * purpose: without it a repeated delivery of the same approval would credit the
 * client twice, and the CRM cannot know whether its first call reached us.
 */
class AdjustDepositRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        $max = (float) config('trading.bridge.max_amount', 1000000);

        return [
            'user_id' => ['required', 'integer', 'min:1', 'exists:users,id'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:'.$max],
            'reference' => ['required', 'string', 'min:1', 'max:64'],
            'operator_name' => ['nullable', 'string', 'max:120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user_id.exists' => 'Клиент не найден.',
            'amount.required' => 'Укажите сумму пополнения.',
            'amount.numeric' => 'Сумма должна быть числом.',
            'amount.min' => 'Сумма пополнения должна быть больше нуля.',
            'reference.required' => 'Не передан ключ идемпотентности заявки.',
        ];
    }
}
