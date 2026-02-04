<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Beneficiary;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBeneficiaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = auth()->user();

        if ($user->isProvincialStaff() && $user->isAdmin()) {
            return true;
        }

        // Municipal admin can update beneficiaries in their own municipality
        if ($user->isAdmin()) {
            $beneficiary = Beneficiary::find($this->route('id'));

            return $beneficiary && $beneficiary->home_municipality_id === $user->municipality_id;
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'home_municipality_id' => ['sometimes', 'integer', 'exists:municipalities,id'],
            'first_name'           => ['sometimes', 'string', 'max:50'],
            'last_name'            => ['sometimes', 'string', 'max:50'],
            'middle_name'          => ['nullable', 'string', 'max:50'],
            'suffix'               => ['nullable', 'string', 'max:10'],
            'birthdate'            => ['sometimes', 'date', 'before:today'],
            'gender'               => ['sometimes', 'string', Rule::in(['Male', 'Female', 'Other'])],
            'contact_number'       => ['nullable', 'string', 'max:20'],
            'address'              => ['nullable', 'string', 'max:500'],
            'barangay'             => ['nullable', 'string', 'max:100'],
            'id_type'              => ['nullable', 'string', 'max:50'],
            'id_number'            => ['nullable', 'string', 'max:100'],
        ];
    }
}
