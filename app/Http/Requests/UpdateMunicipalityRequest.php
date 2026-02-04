<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMunicipalityRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = auth()->user();

        // Provincial admin can update any municipality
        if ($user->isProvincialStaff() && $user->isAdmin()) {
            return true;
        }

        // Municipal admin can update their own municipality
        return $user->isAdmin() && (int) $user->municipality_id === (int) $this->route('id');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $id = $this->route('id');

        return [
            'name'             => ['sometimes', 'string', 'max:100', Rule::unique('municipalities', 'name')->ignore($id)],
            'code'             => ['sometimes', 'string', 'max:20', Rule::unique('municipalities', 'code')->ignore($id)],
            'logo_path'        => ['nullable', 'string'],
            'address'          => ['nullable', 'string'],
            'contact_phone'    => ['nullable', 'string', 'max:20'],
            'contact_email'    => ['nullable', 'email', 'max:100'],
            'status'           => ['nullable', Rule::in(['ACTIVE', 'SUSPENDED', 'INACTIVE'])],
            'is_active'        => ['nullable', 'boolean'],
            'allocated_budget' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
