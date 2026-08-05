<?php

declare(strict_types=1);

namespace Modules\Core\Platform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SendNotificationRequest extends FormRequest
{
    /**
     * Authentication dan tenant authorization ditangani oleh
     * InjectTenantContext sebelum request divalidasi.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalisasi input textual sebelum rule validation dijalankan.
     */
    protected function prepareForValidation(): void
    {
        $normalizedInput = [];

        $recipient = $this->input('recipient');

        if (is_string($recipient)) {
            $normalizedInput['recipient'] = trim($recipient);
        }

        $body = $this->input('body');

        if (is_string($body)) {
            $normalizedInput['body'] = trim($body);
        }

        $options = $this->input('options');

        if (is_array($options)) {
            if (
                array_key_exists('title', $options)
                && is_string($options['title'])
            ) {
                $options['title'] = trim($options['title']);
            }

            $normalizedInput['options'] = $options;
        }

        if ($normalizedInput !== []) {
            $this->merge($normalizedInput);
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'recipient' => [
                'required',
                'string',
                'max:150',
            ],
            'body' => [
                'required',
                'string',
                'max:5000',
            ],
            /*
             * Hanya title yang diizinkan sebagai option dari client.
             *
             * Runtime identity seperti operator ID atau internal recipient
             * ID tidak boleh diterima dari request body.
             */
            'options' => [
                'nullable',
                'array:title',
            ],
            'options.title' => [
                'nullable',
                'string',
                'max:200',
            ],
            'options.user_id' => [
                'prohibited',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'options.array' => 'Notification options must be an object containing only supported fields.',
            'options.user_id.prohibited' => 'Notification user identity cannot be supplied by the client.',
        ];
    }
}
