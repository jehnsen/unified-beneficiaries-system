<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * RevokePairRequest
 *
 * Validates the request to revoke a verified beneficiary pair.
 * This is used when an admin wants to undo a previous verification.
 */
class RevokePairRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Authorization is handled in the controller with more context.
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
            'revocation_reason' => [
                'required',
                'string',
                'min:10',
                'max:1000'
            ],
        ];
    }

    /**
     * Get custom error messages.
     */
    public function messages(): array
    {
        return [
            'revocation_reason.min' => 'Please provide a detailed reason for revocation (at least 10 characters).',
        ];
    }

    /**
     * Get custom attribute names for error messages.
     */
    public function attributes(): array
    {
        return [
            'revocation_reason' => 'revocation reason',
        ];
    }
}
