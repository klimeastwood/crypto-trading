<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Self-registration of a trading client.
 *
 * The email is normalised to lower case before the "unique" rule runs, so
 * "Ivan@Example.com" and "ivan@example.com" cannot become two accounts. The
 * password is confirmed and every message is written in Russian, like the rest
 * of the client-facing forms.
 */
class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route is behind the "guest" middleware; this is a second guard.
        return $this->user() === null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'email' => Str::lower(trim((string) $this->input('email'))),
            'phone' => blank($this->input('phone')) ? null : trim((string) $this->input('phone')),
        ]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^\+?[0-9\s()\-]{7,32}$/'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
            'terms' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Укажите имя.',
            'name.min' => 'Имя должно содержать минимум 2 символа.',
            'name.max' => 'Имя не длиннее 120 символов.',
            'email.required' => 'Укажите e-mail.',
            'email.email' => 'Введите корректный e-mail.',
            'email.unique' => 'Этот e-mail уже зарегистрирован — войдите в кабинет.',
            'phone.regex' => 'Телефон в формате +375 29 123-45-67.',
            'phone.max' => 'Телефон не длиннее 32 символов.',
            'password.required' => 'Придумайте пароль.',
            'password.min' => 'Пароль должен содержать минимум 8 символов.',
            'password.max' => 'Пароль не длиннее 255 символов.',
            'password.confirmed' => 'Пароли не совпадают.',
            'terms.accepted' => 'Подтвердите согласие с условиями использования.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'имя',
            'email' => 'e-mail',
            'phone' => 'телефон',
            'password' => 'пароль',
            'terms' => 'согласие с условиями',
        ];
    }
}
