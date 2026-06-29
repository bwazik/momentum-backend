<?php

namespace App\Modules\Document\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class UploadDocumentVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $maxMb = tenant()?->settings['max_upload_size_mb'] ?? 20;

        return [
            'file' => [
                'required',
                File::types([
                    'application/pdf',
                    'image/jpeg',
                    'image/png',
                    'image/gif',
                    'application/msword',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'application/vnd.ms-excel',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                ])->max($maxMb * 1024),
            ],
        ];
    }
}
