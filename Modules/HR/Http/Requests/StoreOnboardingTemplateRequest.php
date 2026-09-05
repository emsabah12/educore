<?php

declare(strict_types=1);

namespace Modules\HR\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreOnboardingTemplateRequest extends FormRequest
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
            'code' => [
                'required',
                'string',
                'max:50',
            ],
            'name' => [
                'required',
                'string',
                'max:150',
            ],
            'tasks' => [
                'nullable',
                'array',
            ],
            'tasks.*.code' => [
                'required_with:tasks',
                'string',
                'max:50',
            ],
            'tasks.*.title' => [
                'required_with:tasks',
                'string',
                'max:255',
            ],
            'tasks.*.category' => [
                'required_with:tasks',
                'string',
                'in:DOCUMENT,ORIENTATION,CONTRACT,ADMIN',
            ],
            'tasks.*.sequence' => [
                'required_with:tasks',
                'integer',
                'min:1',
            ],
            'tasks.*.is_required' => [
                'nullable',
                'boolean',
            ],
            'tasks.*.requires_evidence' => [
                'nullable',
                'boolean',
            ],
        ];
    }
}
