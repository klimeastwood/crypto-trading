<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Payload sent by Krayin CRM when an operator approves, rejects or completes a
 * payout.
 *
 * The decision is idempotent: sending the same `status` for a request that is
 * already in that state answers with `applied = false`, so a retried bridge
 * call cannot flip the decision or overwrite the note. A different status on a
 * reviewed request is refused, and `paid` is accepted only after `approved`.
 */
class ReviewWithdrawalRequest extends FormRequest
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
        return [
            'request_id' => ['required', 'integer', 'min:1', 'exists:withdrawal_requests,id'],
            'status' => ['required', 'string', 'in:approved,rejected,paid'],
            'note' => ['nullable', 'string', 'max:500'],
            'reviewer_id' => ['nullable', 'integer', 'min:1', 'exists:users,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'request_id.required' => 'Не передан идентификатор заявки на вывод.',
            'request_id.exists' => 'Заявка на вывод не найдена.',
            'status.required' => 'Не передано решение по заявке.',
            'status.in' => 'Решение по заявке может быть только «одобрено», «отклонено» или «выплачено».',
            'reviewer_id.exists' => 'Оператор, принявший решение, не найден.',
            'note.max' => 'Комментарий к решению слишком длинный.',
        ];
    }
}
