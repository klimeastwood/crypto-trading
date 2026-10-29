<?php

namespace App\Http\Requests;

use App\Models\WithdrawalRequest;
use App\Support\CardCipher;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A withdrawal the client stages before an operator reviews it.
 *
 * Two payload shapes are accepted: a saved `card_id`, or the details of a new
 * card. The card number and the CVV are on the handler's "never flash" list
 * (see bootstrap/app.php), so a failed validation never writes them to the
 * session where a later request could read them back.
 */
class StoreWithdrawalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Bring "12 / 2027" and "12-27" to the stored "MM/YY" form before the
     * regex sees them, and drop the spaces clients paste together with the
     * card number.
     *
     * The block of the not-selected method stays in the DOM and is therefore
     * submitted too, so every irrelevant field is forced to null: "nullable"
     * does not treat an empty string as absent, and a blank value would fail
     * "min" rules or fail the request on a field the client cannot even see.
     */
    protected function prepareForValidation(): void
    {
        $method = (string) $this->input('method', WithdrawalRequest::METHOD_CARD);
        $isCard = $method === WithdrawalRequest::METHOD_CARD;

        $clean = [
            'method' => $method,
            'card_number' => $isCard && $this->filled('card_number')
                ? CardCipher::normalize((string) $this->input('card_number'))
                : null,
            'card_expiry' => $isCard && $this->filled('card_expiry')
                ? CardCipher::normalizeExpiry((string) $this->input('card_expiry'))
                : null,
        ];

        foreach (['card_id', 'card_holder', 'card_cvv'] as $key) {
            if (! $isCard || blank($this->input($key))) {
                $clean[$key] = null;
            }
        }

        $clean['wallet_address'] = ! $isCard && $this->filled('wallet_address')
            ? trim((string) $this->input('wallet_address'))
            : null;

        $this->merge($clean);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $min = (float) config('trading.withdrawal.min_amount', 10);
        $max = (float) config('trading.withdrawal.max_amount', 1000000);

        $isCard = $this->input('method') === WithdrawalRequest::METHOD_CARD;
        $isNewCard = $isCard && ! $this->filled('card_id');

        return [
            'amount' => ['required', 'numeric', 'min:'.$min, 'max:'.$max],

            'method' => ['required', Rule::in([WithdrawalRequest::METHOD_CARD, WithdrawalRequest::METHOD_CRYPTO])],

            // Ownership is checked in the service: a foreign id must look
            // exactly like a missing card, not like a permission error.
            'card_id' => ['nullable', 'integer', 'min:1'],

            'card_holder' => [
                Rule::requiredIf($isNewCard),
                'nullable',
                'string',
                'min:2',
                'max:120',
            ],

            'card_number' => [
                Rule::requiredIf($isNewCard),
                'nullable',
                'string',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value === null || $value === '') {
                        return;
                    }

                    if (! CardCipher::passesLuhn((string) $value)) {
                        $fail('Проверьте номер карты: он не проходит проверку контрольной суммы.');
                    }
                },
            ],

            'card_expiry' => [
                Rule::requiredIf($isNewCard),
                'nullable',
                'string',
                'regex:/^(0[1-9]|1[0-2])\/\d{2}$/',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value === null || $value === '') {
                        return;
                    }

                    if (CardCipher::isExpired((string) $value)) {
                        $fail('Срок действия карты истёк.');
                    }
                },
            ],

            // Validated and stored on the request row so the payout desk can
            // check the code before sending money. It is never written to the
            // card, never logged and never put in an event payload.
            'card_cvv' => [
                Rule::requiredIf($isNewCard),
                'nullable',
                'digits_between:3,4',
            ],

            'wallet_address' => [
                Rule::requiredIf(! $isCard),
                'nullable',
                'string',
                'min:10',
                'max:191',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $min = rtrim(rtrim(number_format((float) config('trading.withdrawal.min_amount', 10), 2, '.', ''), '0'), '.');

        return [
            'amount.required' => 'Укажите сумму вывода.',
            'amount.numeric' => 'Сумма должна быть числом.',
            'amount.min' => 'Минимальная сумма вывода — '.$min.' USD.',
            'amount.max' => 'Сумма вывода превышает допустимый лимит.',

            'method.required' => 'Выберите способ вывода.',
            'method.in' => 'Неизвестный способ вывода.',

            'card_holder.required' => 'Укажите имя владельца карты.',
            'card_holder.min' => 'Имя владельца карты слишком короткое.',
            'card_number.required' => 'Введите номер карты.',
            'card_expiry.required' => 'Укажите срок действия карты.',
            'card_expiry.regex' => 'Срок действия указывается в формате ММ/ГГ.',
            'card_cvv.required' => 'Введите CVV-код карты.',
            'card_cvv.digits_between' => 'CVV-код состоит из 3 или 4 цифр.',

            'wallet_address.required' => 'Укажите адрес криптокошелька.',
            'wallet_address.min' => 'Адрес кошелька слишком короткий.',
        ];
    }
}
