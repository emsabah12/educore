<?php

declare(strict_types=1);

namespace Modules\Academic\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class BulkGradeRequest extends FormRequest
{
    /**
     * Menentukan apakah request diizinkan diproses.
     *
     * Otorisasi bisnis tenant, active context, dan role/position
     * dilakukan pada application/service layer.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Aturan validasi input bulk grading.
     *
     * Catatan:
     * - teacher_id TIDAK diterima dari client.
     * - Identitas guru/penginput harus diturunkan dari authenticated user
     *   dan active context pada application layer.
     * - student_id mengacu pada tabel canonical `students`.
     * - Validasi tenant isolation tetap wajib dilakukan pada
     *   application/service layer.
     */
    public function rules(): array
    {
        return [
            'assessment_setting_id' => [
                'required',
                'uuid',
                'exists:assessment_settings,id',
            ],

            'grades' => [
                'required',
                'array',
                'min:1',
            ],

            'grades.*.student_id' => [
                'required',
                'uuid',
                'exists:students,id',
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
     * Pesan validasi custom agar error API lebih mudah dipahami.
     */
    public function messages(): array
    {
        return [
            'assessment_setting_id.required' =>
            'ID pengaturan penilaian wajib diisi.',

            'assessment_setting_id.uuid' =>
            'ID pengaturan penilaian harus berupa UUID yang valid.',

            'assessment_setting_id.exists' =>
            'Pengaturan penilaian tidak ditemukan.',

            'grades.required' =>
            'Data nilai wajib diisi.',

            'grades.array' =>
            'Data nilai harus berupa array.',

            'grades.min' =>
            'Minimal satu data nilai harus dikirim.',

            'grades.*.student_id.required' =>
            'ID student wajib diisi pada setiap baris nilai.',

            'grades.*.student_id.uuid' =>
            'ID student harus berupa UUID yang valid.',

            'grades.*.student_id.exists' =>
            'Student tidak ditemukan.',

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
