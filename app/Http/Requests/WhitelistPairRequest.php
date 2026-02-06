<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WhitelistPairRequest
 *
 * Validates the request to whitelist (verify) a pair of beneficiaries.
 * This is used when an admin manually verifies that two phonetically similar
 * beneficiaries are actually distinct (different people) or duplicates (same person).
 */
class WhitelistPairRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Authorization is handled in the controller with more context
     * (Provincial vs Municipal scoping).
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
            // Beneficiary UUIDs (public-facing identifiers)
            'beneficiary_a_uuid' => [
                'required',
                'string',
                'exists:beneficiaries,uuid'
            ],
            'beneficiary_b_uuid' => [
                'required',
                'string',
                'exists:beneficiaries,uuid',
                'different:beneficiary_a_uuid' // Can't whitelist a beneficiary with itself
            ],

            // Verification details
            'verification_status' => [
                'required',
                'string',
                Rule::in(['VERIFIED_DISTINCT', 'VERIFIED_DUPLICATE'])
            ],
            'verification_reason' => [
                'required',
                'string',
                'min:10',
                'max:1000'
            ],
            'notes' => [
                'nullable',
                'string',
                'max:1000'
            ],

            // Similarity metrics (captured from fraud detection for audit trail)
            'similarity_score' => [
                'nullable',
                'integer',
                'min:0',
                'max:100'
            ],
            'levenshtein_distance' => [
                'nullable',
                'integer',
                'min:0'
            ],
        ];
    }

    /**
     * Get custom error messages.
     */
    public function messages(): array
    {
        return [
            'beneficiary_b_uuid.different' => 'Cannot verify a beneficiary with itself.',
            'verification_reason.min' => 'Please provide a detailed reason (at least 10 characters).',
            'verification_status.in' => 'Invalid verification status. Must be VERIFIED_DISTINCT or VERIFIED_DUPLICATE.',
        ];
    }

    /**
     * Get custom attribute names for error messages.
     */
    public function attributes(): array
    {
        return [
            'beneficiary_a_uuid' => 'first beneficiary',
            'beneficiary_b_uuid' => 'second beneficiary',
            'verification_status' => 'verification status',
            'verification_reason' => 'reason for verification',
        ];
    }
}
