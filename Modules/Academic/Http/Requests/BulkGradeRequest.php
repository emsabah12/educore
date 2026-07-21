<?php

declare(strict_types=1);

namespace Modules\Academic\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class BulkGradeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Memastikan struktur payload array massal tervalidasi dengan ketat.
     */
    public function rules(): array
    {
        return [
            'assessment_setting_id' => ['required', 'uuid', 'exists:assessment_settings,id'],
            'teacher_id'            => ['required', 'uuid'],
            'grades'                => ['required', 'array', 'min:1'],
            'grades.*.santri_id'    => ['required', 'uuid', 'exists:santris,id'],
            'grades.*.score'        => ['required', 'numeric', 'between:0.00,100.00'],
            'grades.*.notes'        => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'assessment_setting_id.required' => 'Konteks komponen penilaian wajib ditentukan.',
            'assessment_setting_id.exists'   => 'Komponen penilaian tidak terdaftar di sistem.',
            'grades.required'                => 'Daftar nilai santri tidak boleh kosong.',
            'grades.*.santri_id.required'    => 'ID Santri wajib diisi pada baris data.',
            'grades.*.score.between'         => 'Skor nilai harus berada dalam rentang skala 0.00 sampai 100.00.',
        ];
    }
}
