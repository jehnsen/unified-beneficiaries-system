<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->user()->isAdmin();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name'            => ['required', 'string', 'max:100'],
            'email'           => ['required', 'email', 'max:100', 'unique:users,email'],
            'password'        => ['required', 'string', 'min:8', 'confirmed'],
            'role'            => ['required', Rule::in(['ADMIN', 'ENCODER', 'REVIEWER'])],
            'municipality_id' => ['nullable', 'integer', 'exists:municipalities,id'],
            'is_active'       => ['nullable', 'boolean'],
        ];
    }

    /**
     * Municipal admins can only create users for their own municipality.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $user = auth()->user();

            // Municipal admin cannot create provincial staff or users for other municipalities
            if ($user->isMunicipalStaff()) {
                $municipalityId = $this->input('municipality_id');

                if ($municipalityId === null) {
                    $validator->errors()->add('municipality_id', 'Municipal admins cannot create provincial staff.');
                } elseif ((int) $municipalityId !== $user->municipality_id) {
                    $validator->errors()->add('municipality_id', 'You can only create users for your own municipality.');
                }
            }
        });
    }
}
