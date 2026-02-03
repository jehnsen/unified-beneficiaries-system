<?php

declare(strict_types=1);

/**
 * UBIS Provincial Grid - Global Helper Functions
 *
 * This file contains global helper functions used throughout the UBIS application.
 * These functions are autoloaded by Composer and available everywhere.
 */

if (!function_exists('phonetic_hash')) {
    /**
     * Generate phonetic hash for name matching (fraud detection)
     *
     * Uses SOUNDEX by default, can be switched to METAPHONE via config
     *
     * @param string $text The text to convert to phonetic hash
     * @return string The phonetic hash
     */
    function phonetic_hash(string $text): string
    {
        $algorithm = config('ubis.phonetic_algorithm', 'soundex');

        return match ($algorithm) {
            'metaphone' => metaphone($text),
            'soundex' => soundex($text),
            default => soundex($text),
        };
    }
}

if (!function_exists('format_amount')) {
    /**
     * Format amount in Philippine Peso format
     *
     * @param float|string $amount The amount to format
     * @param bool $includeSymbol Include ₱ symbol
     * @return string Formatted amount
     */
    function format_amount(float|string $amount, bool $includeSymbol = true): string
    {
        $formatted = number_format((float) $amount, 2, '.', ',');

        return $includeSymbol ? "₱{$formatted}" : $formatted;
    }
}

if (!function_exists('mask_contact_number')) {
    /**
     * Mask contact number for inter-LGU visibility
     *
     * @param string|null $contactNumber The contact number to mask
     * @return string Masked contact number
     */
    function mask_contact_number(?string $contactNumber): string
    {
        if (empty($contactNumber)) {
            return 'N/A';
        }

        return '***-****-' . substr($contactNumber, -4);
    }
}

if (!function_exists('mask_id_number')) {
    /**
     * Mask ID number for inter-LGU visibility
     *
     * @param string|null $idNumber The ID number to mask
     * @return string Masked ID number
     */
    function mask_id_number(?string $idNumber): string
    {
        if (empty($idNumber)) {
            return 'N/A';
        }

        return str_repeat('*', max(0, strlen($idNumber) - 4)) . substr($idNumber, -4);
    }
}

if (!function_exists('is_provincial_staff')) {
    /**
     * Check if the current authenticated user is Provincial Staff
     *
     * @return bool
     */
    function is_provincial_staff(): bool
    {
        $user = auth()->user();

        return $user && $user->municipality_id === null;
    }
}

if (!function_exists('is_municipal_staff')) {
    /**
     * Check if the current authenticated user is Municipal Staff
     *
     * @return bool
     */
    function is_municipal_staff(): bool
    {
        $user = auth()->user();

        return $user && $user->municipality_id !== null;
    }
}

if (!function_exists('current_municipality_id')) {
    /**
     * Get the current user's municipality ID
     *
     * @return int|null
     */
    function current_municipality_id(): ?int
    {
        return auth()->user()?->municipality_id;
    }
}

if (!function_exists('calculate_levenshtein_similarity')) {
    /**
     * Calculate similarity between two strings using Levenshtein distance
     *
     * Returns a percentage (0-100) where 100 is identical
     *
     * @param string $str1 First string
     * @param string $str2 Second string
     * @return float Similarity percentage
     */
    function calculate_levenshtein_similarity(string $str1, string $str2): float
    {
        $str1 = strtolower(trim($str1));
        $str2 = strtolower(trim($str2));

        if ($str1 === $str2) {
            return 100.0;
        }

        $maxLength = max(strlen($str1), strlen($str2));

        if ($maxLength === 0) {
            return 100.0;
        }

        $distance = levenshtein($str1, $str2);

        return round((1 - ($distance / $maxLength)) * 100, 2);
    }
}

if (!function_exists('get_assistance_types')) {
    /**
     * Get list of valid assistance types
     *
     * @return array
     */
    function get_assistance_types(): array
    {
        return [
            'Medical',
            'Cash',
            'Burial',
            'Educational',
            'Food',
            'Disaster Relief',
        ];
    }
}

if (!function_exists('get_claim_statuses')) {
    /**
     * Get list of valid claim statuses
     *
     * @return array
     */
    function get_claim_statuses(): array
    {
        return [
            'PENDING',
            'UNDER_REVIEW',
            'APPROVED',
            'DISBURSED',
            'REJECTED',
            'CANCELLED',
        ];
    }
}

if (!function_exists('format_philippines_address')) {
    /**
     * Format address in Philippine standard format
     *
     * @param array $parts Address parts (street, barangay, municipality, province)
     * @return string Formatted address
     */
    function format_philippines_address(array $parts): string
    {
        return implode(', ', array_filter($parts));
    }
}

if (!function_exists('generate_google_maps_url')) {
    /**
     * Generate Google Maps URL from coordinates
     *
     * @param float $latitude Latitude
     * @param float $longitude Longitude
     * @return string Google Maps URL
     */
    function generate_google_maps_url(float $latitude, float $longitude): string
    {
        return "https://www.google.com/maps?q={$latitude},{$longitude}";
    }
}

if (!function_exists('sanitize_file_name')) {
    /**
     * Sanitize filename for storage
     *
     * @param string $filename Original filename
     * @return string Sanitized filename
     */
    function sanitize_file_name(string $filename): string
    {
        // Remove any path components
        $filename = basename($filename);

        // Replace spaces with underscores
        $filename = str_replace(' ', '_', $filename);

        // Remove any character that isn't alphanumeric, underscore, hyphen, or dot
        $filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $filename);

        return $filename;
    }
}

if (!function_exists('calculate_age')) {
    /**
     * Calculate age from birthdate
     *
     * @param string|\DateTime $birthdate Birthdate
     * @return int Age in years
     */
    function calculate_age(string|\DateTime $birthdate): int
    {
        if (is_string($birthdate)) {
            $birthdate = new \DateTime($birthdate);
        }

        $now = new \DateTime();

        return (int) $now->diff($birthdate)->y;
    }
}

if (!function_exists('get_risk_level_color')) {
    /**
     * Get color code for risk level (for UI/reporting)
     *
     * @param string $riskLevel Risk level (LOW, MEDIUM, HIGH)
     * @return string Hex color code
     */
    function get_risk_level_color(string $riskLevel): string
    {
        return match (strtoupper($riskLevel)) {
            'LOW' => '#22c55e',      // Green
            'MEDIUM' => '#f59e0b',   // Orange
            'HIGH' => '#ef4444',     // Red
            default => '#6b7280',    // Gray
        };
    }
}

if (!function_exists('log_audit')) {
    /**
     * Log audit trail for sensitive operations
     *
     * @param string $action Action performed
     * @param string $model Model affected
     * @param int|null $modelId Model ID
     * @param array $data Additional data
     * @return void
     */
    function log_audit(string $action, string $model, ?int $modelId = null, array $data = []): void
    {
        \Illuminate\Support\Facades\Log::channel('audit')->info($action, [
            'user_id' => auth()->id(),
            'user_name' => auth()->user()?->name,
            'municipality_id' => current_municipality_id(),
            'model' => $model,
            'model_id' => $modelId,
            'action' => $action,
            'data' => $data,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
