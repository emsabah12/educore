<?php

declare(strict_types=1);

namespace Modules\HR\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * §15.6 — "Client cannot submit `decided_by_membership_id`." Field itu
 * SENGAJA tidak ada di rules() sama sekali — approver selalu diambil
 * dari Membership terautentikasi (request attribute), bukan input body.
 */
final class DecideLeaveRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'note' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ];
    }
}
