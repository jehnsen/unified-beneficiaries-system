<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMunicipalityRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = auth()->user();

        return $user->isProvincialStaff() && $user->isAdmin();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name'             => ['required', 'string', 'max:100', 'unique:municipalities,name'],
            'code'             => ['required', 'string', 'max:20', 'unique:municipalities,code'],
            'logo_path'        => ['nullable', 'string'],
            'address'          => ['nullable', 'string'],
            'contact_phone'    => ['nullable', 'string', 'max:20'],
            'contact_email'    => ['nullable', 'email', 'max:100'],
            'status'           => ['nullable', Rule::in(['ACTIVE', 'SUSPENDED', 'INACTIVE'])],
            'allocated_budget' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
