<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Payload sent by Krayin CRM when an operator changes a balance.
 *
 * The validation deliberately mirrors the CRM form: a signed USD amount, a
 * human readable reason and the id of the CRM adjustment that makes a retry
 * idempotent.
 */
class AdjustBalanceRequest extends FormRequest
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
            'amount' => ['required', 'numeric', 'min:'.(-1 * $max), 'max:'.$max, function (string $attribute, mixed $value, Closure $fail): void {
                if ((float) $value === 0.0) {
                    $fail('Сумма корректировки не может быть нулевой.');
                }
            }],
            'reason' => ['required', 'string', 'min:2', 'max:255'],
            'adjustment_id' => ['nullable', 'integer', 'min:1'],
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
            'amount.required' => 'Укажите сумму корректировки.',
            'amount.numeric' => 'Сумма должна быть числом.',
            'reason.required' => 'Укажите причину корректировки.',
            'reason.min' => 'Причина слишком короткая.',
        ];
    }
}
