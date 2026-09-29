<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

class StoreConversationMessageRequest extends FormRequest
{
    private const ALLOWED_MEDIA_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/bmp',
        'video/mp4',
        'audio/mpeg',
        'audio/mp4',
        'audio/ogg',
        'audio/webm',
        'audio/wav',
        'audio/x-wav',
        'application/pdf',
        'text/plain',
        'application/sql',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    private const ALLOWED_FALLBACK_EXTENSIONS = [
        'bmp',
        'doc',
        'docx',
        'gif',
        'int',
        'jpeg',
        'jpg',
        'm4a',
        'mp3',
        'mp4',
        'ogg',
        'opus',
        'pdf',
        'png',
        'rs',
        'sql',
        'txt',
        'wav',
        'webm',
        'webp',
        'xls',
        'xlsx',
    ];

    private const MAX_TOTAL_MEDIA_KILOBYTES = 51200;

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
            'media' => ['nullable', 'array', 'max:3'],
            'media.*' => ['file', 'max:25600'],
            'ptt' => ['nullable', 'boolean'],
            'voice' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $files = $this->mediaFiles();
            $totalKilobytes = 0;

            foreach ($files as $index => $file) {
                $totalKilobytes += (int) ceil(($file->getSize() ?: 0) / 1024);

                if (! $this->isAllowedMediaFile($file)) {
                    $validator->errors()->add("media.{$index}", 'Tipo de archivo no permitido.');
                }
            }

            if ($totalKilobytes > self::MAX_TOTAL_MEDIA_KILOBYTES) {
                $validator->errors()->add('media', 'Los adjuntos no pueden superar 50 MB en total.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required_without' => 'Escribí un mensaje o adjuntá un archivo.',
            'body.string' => 'El mensaje debe ser texto válido.',
            'body.max' => 'El mensaje no puede superar los 4000 caracteres.',
            'idempotency_key.required' => 'No se pudo preparar el envío. Actualizá la página e intentá de nuevo.',
            'idempotency_key.string' => 'La clave de envío no es válida. Actualizá la página e intentá de nuevo.',
            'idempotency_key.max' => 'La clave de envío es demasiado larga. Actualizá la página e intentá de nuevo.',
            'media.array' => 'Los adjuntos no son válidos.',
            'media.max' => 'Podés enviar hasta 3 archivos por vez.',
            'media.*.file' => 'El adjunto debe ser un archivo válido.',
            'media.*.max' => 'Cada adjunto puede pesar hasta 25 MB.',
            'ptt.boolean' => 'El indicador de audio PTT no es válido.',
            'voice.boolean' => 'El indicador de audio no es válido.',
        ];
    }

    /**
     * @return list<UploadedFile>
     */
    public function mediaFiles(): array
    {
        $files = $this->file('media', []);

        if ($files instanceof UploadedFile) {
            return [$files];
        }

        if (! is_array($files)) {
            return [];
        }

        return array_values(array_filter($files, fn (mixed $file): bool => $file instanceof UploadedFile));
    }

    private function isAllowedMediaFile(UploadedFile $file): bool
    {
        $mimeType = strtolower($file->getMimeType() ?: 'application/octet-stream');
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: '');

        if (in_array($mimeType, self::ALLOWED_MEDIA_MIME_TYPES, true)) {
            return true;
        }

        return in_array($extension, self::ALLOWED_FALLBACK_EXTENSIONS, true)
            && in_array($mimeType, ['application/octet-stream', 'text/plain', 'text/x-sql'], true);
    }
}
