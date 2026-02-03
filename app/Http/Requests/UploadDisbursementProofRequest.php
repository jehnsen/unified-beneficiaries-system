<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadDisbursementProofRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return auth()->check();
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            // Required Documents
            'photo' => ['required', 'image', 'max:5120', 'mimes:jpg,jpeg,png'],
            'signature' => ['required', 'image', 'max:2048', 'mimes:jpg,jpeg,png'],
            'id_photo' => ['nullable', 'image', 'max:5120', 'mimes:jpg,jpeg,png'],

            // Geolocation (for audit trail)
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'location_accuracy' => ['nullable', 'string', 'max:20'],
        ];
    }

    /**
     * Get custom error messages.
     */
    public function messages(): array
    {
        return [
            'photo.required' => 'A photo of the beneficiary during disbursement is required.',
            'signature.required' => 'A digital signature is required.',
            'photo.max' => 'Photo size must not exceed 5MB.',
            'signature.max' => 'Signature size must not exceed 2MB.',
        ];
    }
}
