<?php

declare(strict_types=1);

namespace Modules\HR\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * §15.3 — "`reason` is stored in HR data but not copied verbatim to
 * audit metadata." Field ini disimpan di kolom `note` LeaveBalanceLedger
 * (data HR biasa), TIDAK PERNAH diteruskan ke metadata audit generik
 * Core — konsisten dengan INV-HR-LEAVE-014.
 */
final class AdjustLeaveEntitlementRequest extends FormRequest
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
            'units_delta' => [
                'required',
                'numeric',
                'not_in:0',
            ],
            'reason' => [
                'nullable',
                'string',
                'max:1000',
            ],
            'idempotency_key' => [
                'required',
                'string',
                'max:100',
            ],
        ];
    }
}
