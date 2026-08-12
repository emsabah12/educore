<?php

declare(strict_types=1);

namespace Modules\HR\Http\Requests;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['nama', 'nip', 'jabatan'] as $field) {
            $value = $this->input($field);

            if (! is_string($value)) {
                continue;
            }

            $value = trim($value);

            if ($field === 'jabatan') {
                $value = strtoupper($value);
            }

            $normalized[$field] = $value;
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $tenantId = $this->attributes->get(
            'authenticated_tenant_id',
        );

        return [
            'nama' => [
                'required',
                'string',
                'min:3',
                'max:255',
            ],
            'nip' => [
                'required',
                'string',
                'max:50',
                Rule::unique('employees', 'nip')
                    ->where(
                        static fn (Builder $query): Builder => $query->where(
                            'tenant_id',
                            is_string($tenantId) ? $tenantId : '',
                        ),
                    ),
            ],
            'jabatan' => [
                'required',
                'string',
                Rule::in([
                    'GURU',
                    'KEPALA_SEKOLAH',
                    'STAFF',
                ]),
            ],
            'email' => [
                'prohibited',
            ],
        ];
    }
}
