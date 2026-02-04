<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBeneficiaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'home_municipality_id' => ['required', 'integer', 'exists:municipalities,id'],
            'first_name'           => ['required', 'string', 'max:50'],
            'last_name'            => ['required', 'string', 'max:50'],
            'middle_name'          => ['nullable', 'string', 'max:50'],
            'suffix'               => ['nullable', 'string', 'max:10'],
            'birthdate'            => ['required', 'date', 'before:today'],
            'gender'               => ['required', 'string', Rule::in(['Male', 'Female', 'Other'])],
            'contact_number'       => ['nullable', 'string', 'max:20'],
            'address'              => ['nullable', 'string', 'max:500'],
            'barangay'             => ['nullable', 'string', 'max:100'],
            'id_type'              => ['nullable', 'string', 'max:50'],
            'id_number'            => ['nullable', 'string', 'max:100'],
            'skip_duplicate_check' => ['nullable', 'boolean'],
        ];
    }
}
