<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreConversationMessageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'body' => ['nullable', 'required_without:media', 'string', 'max:4000'],
            'idempotency_key' => ['required', 'string', 'max:100'],
            'media' => ['nullable', 'file', 'max:25600'],
            'ptt' => ['nullable', 'boolean'],
            'voice' => ['nullable', 'boolean'],
        ];
    }
}
