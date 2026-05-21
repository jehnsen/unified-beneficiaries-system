<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReverseClaimRequest extends FormRequest
{
    /**
     * Only Provincial Admin can reverse a disbursed claim.
     *
     * Municipal staff lack the cross-municipality context needed to assess
     * whether a reversal is appropriate, and budget rollback has province-wide
     * fiscal implications that require Admin authority.
     */
    public function authorize(): bool
    {
        $user = auth()->user();

        return $user && $user->isProvincialStaff() && $user->isAdmin();
    }

    public function rules(): array
    {
        return [
            'reversal_reason' => ['required', 'string', 'min:20', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'reversal_reason.min' => 'A reversal reason must be at least 20 characters for audit purposes.',
        ];
    }
}
