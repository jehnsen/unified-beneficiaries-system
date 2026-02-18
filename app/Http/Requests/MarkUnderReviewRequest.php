<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MarkUnderReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = auth()->user();

        // Both municipal and provincial staff can flag a claim for review
        return $user->isMunicipalStaff() || $user->isProvincialStaff();
    }

    public function rules(): array
    {
        return [
            // Optional note explaining why the claim is being placed under review
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
