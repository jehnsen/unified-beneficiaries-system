<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClaimRequest extends FormRequest
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
            // Beneficiary Information
            'home_municipality_id' => ['required', 'integer', 'exists:municipalities,id'],
            'first_name' => ['required', 'string', 'max:50'],
            'last_name' => ['required', 'string', 'max:50'],
            'middle_name' => ['nullable', 'string', 'max:50'],
            'suffix' => ['nullable', 'string', 'max:10'],
            'birthdate' => ['required', 'date', 'before:today'],
            'gender' => ['required', 'string', Rule::in(['Male', 'Female', 'Other'])],
            'contact_number' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:500'],
            'barangay' => ['nullable', 'string', 'max:100'],
            'id_type' => ['nullable', 'string', 'max:50'],
            'id_number' => ['nullable', 'string', 'max:100'],

            // Claim Information
            'municipality_id' => [
                Rule::requiredIf(fn() => auth()->user()?->isProvincialStaff()),
                'nullable',
                'integer',
                'exists:municipalities,id',
            ],
            'assistance_type' => ['required', 'string', Rule::in([
                'Medical',
                'Cash',
                'Burial',
                'Educational',
                'Food',
                'Disaster Relief',
            ])],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999.99'],
            'purpose' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Get custom error messages.
     */
    public function messages(): array
    {
        return [
            'birthdate.before' => 'Birthdate must be before today.',
            'amount.min' => 'Amount must be greater than zero.',
            'amount.max' => 'Amount exceeds maximum limit.',
        ];
    }

    /**
     * Get custom attribute names.
     */
    public function attributes(): array
    {
        return [
            'home_municipality_id' => 'home municipality',
            'assistance_type' => 'assistance type',
        ];
    }
}
