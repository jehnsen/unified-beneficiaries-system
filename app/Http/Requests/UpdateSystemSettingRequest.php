<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Update System Setting Request
 *
 * Validates updates to system configuration settings.
 *
 * Features:
 * - Dynamic validation rules loaded from database
 * - Validates value based on setting's data_type
 * - Optional description updates
 *
 * The validation_rules column in system_settings table contains
 * Laravel validation rules as JSON array, which are dynamically
 * applied to the value field.
 */
class UpdateSystemSettingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Authorization is handled by Gate middleware (can:manage-settings),
     * so this always returns true.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Dynamically loads validation rules from the setting model
     * (injected via route binding).
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        // Get the setting from route binding
        $setting = $this->route('setting');

        // Load validation rules from database
        $baseRules = ['required']; // Fallback

        if ($setting && is_array($setting->validation_rules)) {
            $baseRules = $setting->validation_rules;
        }

        return [
            'value' => $baseRules,
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'value.required' => 'The setting value is required.',
            'value.integer' => 'The setting value must be an integer.',
            'value.min' => 'The setting value must be at least :min.',
            'value.max' => 'The setting value must not exceed :max.',
            'value.numeric' => 'The setting value must be a number.',
            'description.max' => 'The description must not exceed 500 characters.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'value' => 'setting value',
            'description' => 'setting description',
        ];
    }
}
