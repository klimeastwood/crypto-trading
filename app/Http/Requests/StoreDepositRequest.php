<?php

namespace App\Http\Requests;

use App\Support\CardCipher;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A deposit the client stages before an operator reviews it.
 *
 * The card is what makes a deposit credible: the request names the card the
 * money is coming from, so an operator can match it against the bank statement
 * instead of trusting a typed amount. Two payload shapes are accepted — a saved
 * `card_id` or the details of a new card. The card number and the CVV are on
 * the handler's "never flash" list (see bootstrap/app.php), so a failed
 * validation never writes them to the session where a later request could read
 * them back. The card number is sealed before it reaches the database; the CVV
 * is written to the request row, where the payout desk reads it once.
 */
class StoreDepositRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Bring "12 / 2027" and "12-27" to the stored "MM/YY" form before the regex
     * sees them, drop the spaces clients paste together with the card number,
     * and null every field of the saved-card form that was submitted blank.
     *
     * The two blocks share the page and the hidden one still submits its
     * inputs, so an empty string would otherwise fail `min` rules on a field the
     * client cannot even see.
     */
    protected function prepareForValidation(): void
    {
        $hasSavedCard = filled($this->input('card_id'));

        $clean = [
            'card_id' => $hasSavedCard ? $this->input('card_id') : null,

            'card_number' => (! $hasSavedCard && $this->filled('card_number'))
                ? CardCipher::normalize((string) $this->input('card_number'))
                : null,

            'card_expiry' => (! $hasSavedCard && $this->filled('card_expiry'))
                ? CardCipher::normalizeExpiry((string) $this->input('card_expiry'))
                : null,

            'card_holder' => (! $hasSavedCard && $this->filled('card_holder'))
                ? trim((string) $this->input('card_holder'))
                : null,

            'card_cvv' => (! $hasSavedCard && $this->filled('card_cvv'))
                ? (string) $this->input('card_cvv')
                : null,
        ];

        $this->merge($clean);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $min = (float) config('trading.deposit.min_amount', 10);
        $max = (float) config('trading.deposit.max_amount', 1000000);

        $isNewCard = ! $this->filled('card_id');

        return [
            'amount' => ['required', 'numeric', 'min:'.$min, 'max:'.$max],

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

            // Validated and stored on the request row so the operator can
            // check it against the bank before crediting. It is never written
            // to the card, never logged and never put in an event payload.
            'card_cvv' => [
                Rule::requiredIf($isNewCard),
                'nullable',
                'digits_between:3,4',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $min = rtrim(rtrim(number_format((float) config('trading.deposit.min_amount', 10), 2, '.', ''), '0'), '.');

        return [
            'amount.required' => 'Укажите сумму пополнения.',
            'amount.numeric' => 'Сумма должна быть числом.',
            'amount.min' => 'Минимальная сумма пополнения — '.$min.' USD.',
            'amount.max' => 'Сумма пополнения превышает допустимый лимит.',

            'card_holder.required' => 'Укажите имя владельца карты.',
            'card_holder.min' => 'Имя владельца карты слишком короткое.',
            'card_number.required' => 'Введите номер карты, с которой пришли деньги.',
            'card_expiry.required' => 'Укажите срок действия карты.',
            'card_expiry.regex' => 'Срок действия указывается в формате ММ/ГГ.',
            'card_cvv.required' => 'Введите CVV-код карты.',
            'card_cvv.digits_between' => 'CVV-код состоит из 3 или 4 цифр.',
        ];
    }
}
