<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\SystemSetting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * System Settings Seeder
 *
 * Seeds initial configuration for the UBIS Provincial Grid system.
 * These settings replace hardcoded constants in FraudDetectionService
 * and enable runtime configuration by Provincial admins.
 *
 * Categories:
 * - fraud_detection: Fraud risk assessment thresholds
 * - system: System-wide behavior settings
 *
 * Usage:
 *   php artisan db:seed --class=SystemSettingSeeder
 */
class SystemSettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Seeding system settings...');

        $settings = [
            // =====================================================
            // FRAUD DETECTION SETTINGS
            // =====================================================

            [
                'uuid' => (string) Str::uuid(),
                'key' => 'RISK_THRESHOLD_DAYS',
                'value' => '90',
                'data_type' => 'integer',
                'description' => 'Number of days to check for recent claims in fraud detection. Used to determine the historical window for detecting fraud patterns.',
                'validation_rules' => json_encode(['required', 'integer', 'min:1', 'max:365']),
                'category' => 'fraud_detection',
                'is_editable' => true,
                'created_by' => null, // System-seeded
                'updated_by' => null,
            ],

            [
                'uuid' => (string) Str::uuid(),
                'key' => 'SAME_TYPE_THRESHOLD_DAYS',
                'value' => '30',
                'data_type' => 'integer',
                'description' => 'Days threshold for same assistance type (double-dipping detection). Prevents beneficiaries from receiving the same welfare assistance multiple times within this period.',
                'validation_rules' => json_encode(['required', 'integer', 'min:1', 'max:180']),
                'category' => 'fraud_detection',
                'is_editable' => true,
                'created_by' => null,
                'updated_by' => null,
            ],

            [
                'uuid' => (string) Str::uuid(),
                'key' => 'HIGH_FREQUENCY_THRESHOLD',
                'value' => '3',
                'data_type' => 'integer',
                'description' => 'Maximum claims allowed within the risk threshold period before flagging as suspicious. Detects beneficiaries with abnormally high claim frequency.',
                'validation_rules' => json_encode(['required', 'integer', 'min:1', 'max:10']),
                'category' => 'fraud_detection',
                'is_editable' => true,
                'created_by' => null,
                'updated_by' => null,
            ],

            [
                'uuid' => (string) Str::uuid(),
                'key' => 'LEVENSHTEIN_DISTANCE_THRESHOLD',
                'value' => '3',
                'data_type' => 'integer',
                'description' => 'Levenshtein distance threshold for name matching in duplicate detection. Lower values mean stricter matching (fewer false positives).',
                'validation_rules' => json_encode(['required', 'integer', 'min:0', 'max:10']),
                'category' => 'fraud_detection',
                'is_editable' => true,
                'created_by' => null,
                'updated_by' => null,
            ],
        ];

        foreach ($settings as $setting) {
            SystemSetting::create($setting);
        }

        $this->command->info('✓ System settings seeded successfully');
        $this->command->info('  - Fraud Detection: 4 settings');
        $this->command->newLine();
    }
}
