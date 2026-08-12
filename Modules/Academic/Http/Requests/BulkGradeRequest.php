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
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'assessment_setting_id' => [
                'required',
                'string',
                'uuid:7',
            ],
            'teacher_id' => [
                'prohibited',
            ],
            'grades' => [
                'required',
                'array',
                'min:1',
            ],
            'grades.*.student_id' => [
                'required',
                'string',
                'uuid:7',
                'distinct',
            ],
            'grades.*.score' => [
                'required',
                'numeric',
                'min:0',
                'max:100',
            ],
            'grades.*.notes' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'assessment_setting_id.required' =>
                'ID pengaturan penilaian wajib diisi.',
            'assessment_setting_id.uuid' =>
                'ID pengaturan penilaian harus berupa UUIDv7 yang valid.',
            'teacher_id.prohibited' =>
                'Teacher identity ditentukan oleh authenticated employee context.',
            'grades.required' =>
                'Data nilai wajib diisi.',
            'grades.array' =>
                'Data nilai harus berupa array.',
            'grades.min' =>
                'Minimal satu data nilai harus dikirim.',
            'grades.*.student_id.required' =>
                'ID student wajib diisi pada setiap baris nilai.',
            'grades.*.student_id.uuid' =>
                'ID student harus berupa UUIDv7 yang valid.',
            'grades.*.student_id.distinct' =>
                'Student yang sama tidak boleh dikirim lebih dari sekali.',
            'grades.*.score.required' =>
                'Nilai wajib diisi.',
            'grades.*.score.numeric' =>
                'Nilai harus berupa angka.',
            'grades.*.score.min' =>
                'Nilai tidak boleh kurang dari 0.',
            'grades.*.score.max' =>
                'Nilai tidak boleh lebih dari 100.',
            'grades.*.notes.string' =>
                'Catatan nilai harus berupa teks.',
            'grades.*.notes.max' =>
                'Catatan nilai maksimal 1000 karakter.',
        ];
    }
}
