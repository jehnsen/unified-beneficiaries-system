<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Master seeder for UBIS Provincial Grid — Province of Ifugao.
 *
 * Run order is critical due to foreign key constraints:
 *  1. Municipalities (tenants)
 *  2. Users (depend on municipalities)
 *  3. Beneficiaries (depend on municipalities + users)
 *  4. Claims (depend on beneficiaries + municipalities + users)
 *  5. DisbursementProofs (depend on claims + users)
 *
 * Usage:
 *   php artisan migrate:fresh --seed
 *   php artisan db:seed
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('=== UBIS Provincial Grid Seeder — Province of Ifugao ===');
        $this->command->newLine();

        $this->call([
            MunicipalitySeeder::class,
            UserSeeder::class,
            SystemSettingSeeder::class,
            BeneficiarySeeder::class,
            ClaimSeeder::class,
            DisbursementProofSeeder::class,
        ]);

        $this->command->newLine();
        $this->command->info('=== Seeding Complete ===');
        $this->printSummary();
    }

    private function printSummary(): void
    {
        $this->command->newLine();
        $this->command->info('--- Data Summary ---');
        $this->command->info('Municipalities:      ' . \DB::table('municipalities')->count());
        $this->command->info('Users:               ' . \DB::table('users')->count());
        $this->command->info('Beneficiaries:        ' . \DB::table('beneficiaries')->count());
        $this->command->info('Claims:              ' . \DB::table('claims')->count());
        $this->command->info('  - Flagged:         ' . \DB::table('claims')->where('is_flagged', true)->count());
        $this->command->info('  - Disbursed:       ' . \DB::table('claims')->where('status', 'DISBURSED')->count());
        $this->command->info('  - Pending/Review:  ' . \DB::table('claims')->whereIn('status', ['PENDING', 'UNDER_REVIEW'])->count());
        $this->command->info('  - Rejected:        ' . \DB::table('claims')->whereIn('status', ['REJECTED', 'CANCELLED'])->count());
        $this->command->info('Disbursement Proofs: ' . \DB::table('disbursement_proofs')->count());
        $this->command->info('Total Disbursed Amt: PHP ' . number_format((float) \DB::table('claims')->where('status', 'DISBURSED')->sum('amount'), 2));
        $this->command->newLine();
    }
}
