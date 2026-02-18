<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $authUser = auth()->user();

        if (!$authUser->isAdmin()) {
            return false;
        }

        // Provincial admin can update any user
        if ($authUser->isProvincialStaff()) {
            return true;
        }

        // Municipal admin can only update users in their own municipality
        $targetUser = User::find($this->route('id'));

        return $targetUser && $targetUser->municipality_id === $authUser->municipality_id;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $id = $this->route('id');

        return [
            'name'            => ['sometimes', 'string', 'max:100'],
            'email'           => ['sometimes', 'email', 'max:100', Rule::unique('users', 'email')->ignore($id)],
            // Same complexity requirements as StoreUserRequest — password resets must
            // meet the same bar as initial creation for a government system.
            'password'        => ['nullable', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()->uncompromised()],
            'role'            => ['sometimes', Rule::in(['ADMIN', 'ENCODER', 'REVIEWER'])],
            'municipality_id' => ['nullable', 'integer', 'exists:municipalities,id'],
            'is_active'       => ['nullable', 'boolean'],
        ];
    }
}
