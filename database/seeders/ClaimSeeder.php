<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

/**
 * Generates realistic claim records including intentional fraud scenarios.
 *
 * Fraud Scenarios Seeded:
 *  1. Double-Dipping:       Same beneficiary, same assistance type, within 30 days,
 *                           from different municipalities.
 *  2. Cross-Municipality:   Beneficiary claims from a municipality that is NOT their
 *                           home municipality (legitimate in some cases, flagged in others).
 *  3. High Frequency:       4+ claims within 90 days from the same person.
 *  4. Near-Duplicate Names: Slightly different spellings registered in other municipalities
 *                           (e.g., "Enrique" vs "Enrike") — seeded as separate beneficiaries
 *                           that should be caught by the phonetic search.
 *
 * Approximately 8-12% of total claims will have fraud indicators, reflecting
 * a realistic rate for provincial social welfare systems.
 */
class ClaimSeeder extends Seeder
{
    private const ASSISTANCE_TYPES = ['Medical', 'Cash', 'Burial', 'Educational', 'Food', 'Disaster Relief'];

    private const STATUSES = ['PENDING', 'UNDER_REVIEW', 'APPROVED', 'DISBURSED', 'REJECTED', 'CANCELLED'];

    /**
     * Realistic peso amounts per assistance type.
     */
    private const AMOUNT_RANGES = [
        'Medical'          => [2000, 15000],
        'Cash'             => [1000, 5000],
        'Burial'           => [5000, 20000],
        'Educational'      => [1000, 10000],
        'Food'             => [500, 3000],
        'Disaster Relief'  => [2000, 10000],
    ];

    /**
     * Purpose descriptions per type — drawn from real MSWDO case profiles.
     */
    private const PURPOSES = [
        'Medical' => [
            'Hospitalization due to pneumonia',
            'Dialysis treatment at BGHMC',
            'Post-surgery medication expenses',
            'Emergency appendectomy costs',
            'Chemotherapy session assistance',
            'Maintenance medication for hypertension and diabetes',
            'Eye cataract surgery at provincial hospital',
            'Dental extraction and denture fitting',
            'TB treatment medication and laboratory tests',
            'Maternal care and delivery expenses',
            'Physical therapy for stroke rehabilitation',
            'Purchase of wheelchair and orthopedic braces',
            'Laboratory and diagnostic tests for cardiac condition',
            'Confinement due to dengue fever',
            'Assistance for child with cleft palate surgery',
        ],
        'Cash' => [
            'Financial assistance for indigent family',
            'Livelihood starter fund for rice farming',
            'Emergency cash aid due to house fire',
            'Transportation allowance for medical follow-up in Baguio',
            'Cash assistance for solo parent household',
            'Support for family affected by landslide',
            'Seed capital for vegetable gardening project',
            'Aid for family with multiple hospitalized members',
            'Assistance for returning OFW with no livelihood',
            'Emergency support after typhoon damage',
        ],
        'Burial' => [
            'Funeral expenses for deceased household member',
            'Burial assistance for indigent family — cadaver transport from Manila',
            'Coffin and interment costs for senior citizen',
            'Funeral assistance — death due to COVID-19 complications',
            'Burial aid for unidentified deceased found in river',
            'Memorial service and burial lot assistance',
            'Embalming and transport of remains from Baguio City',
            'Death benefits assistance for family of accident victim',
        ],
        'Educational' => [
            'Tuition fee assistance for state university enrollment',
            'School supplies and uniform for 3 elementary pupils',
            'College scholarship gap funding — IFSU student',
            'Educational materials for senior high school',
            'Board exam review fees for nursing graduate',
            'Laptop purchase for online learning during pandemic',
            'Technical-vocational training (TESDA) enrollment',
            'Transportation and boarding expenses for college student',
            'School feeding program supplemental support',
            'Graduation expenses for honor student from indigent family',
        ],
        'Food' => [
            'Rice and grocery assistance for large family',
            'Emergency food pack distribution — post-typhoon',
            'Supplemental feeding for malnourished children',
            'Food assistance for bedridden senior citizen',
            'Grocery support for family under quarantine',
            'Monthly food aid for PWD household',
            'Rice subsidy for farming family with crop failure',
        ],
        'Disaster Relief' => [
            'Emergency relief goods after Typhoon Egay',
            'Shelter repair materials — roof blown off by storm',
            'Evacuation center support and relief packs',
            'Flood damage assistance — rice paddies destroyed',
            'Landslide victim relocation temporary shelter',
            'Emergency relief after earthquake tremors',
            'Agricultural input replacement after crop destruction',
            'Temporary housing materials — fire incident victim',
            'Water and sanitation supplies post-flooding',
            'Emergency clothing and hygiene kits for displaced families',
        ],
    ];

    public function run(): void
    {
        $municipalities = DB::table('municipalities')->get()->keyBy('id');
        $municipalityIds = $municipalities->pluck('id')->toArray();
        $users = DB::table('users')->get();

        // Map municipality_id -> user ids for that municipality
        $municipalUserMap = [];
        foreach ($users as $user) {
            if ($user->municipality_id) {
                $municipalUserMap[$user->municipality_id][] = $user->id;
            }
        }
        $provincialUserIds = $users->whereNull('municipality_id')->pluck('id')->toArray();

        // ---------------------------------------------------------------
        // PHASE 1: Normal Claims (~60% of beneficiaries get 1-2 claims)
        // ---------------------------------------------------------------
        $this->command->info('Phase 1: Generating normal claims...');

        $beneficiaries = DB::table('beneficiaries')
            ->where('is_active', true)
            ->get();

        $normalClaimBeneficiaries = $beneficiaries->random((int) ($beneficiaries->count() * 0.60));
        $claims = [];
        $now = Carbon::now();

        foreach ($normalClaimBeneficiaries as $beneficiary) {
            $numClaims = rand(1, 2);
            for ($i = 0; $i < $numClaims; $i++) {
                $type = self::ASSISTANCE_TYPES[array_rand(self::ASSISTANCE_TYPES)];
                $status = $this->weightedStatus();
                $createdAt = Carbon::now()->subDays(rand(1, 365));

                $claims[] = $this->buildClaim(
                    beneficiaryId: $beneficiary->id,
                    municipalityId: $beneficiary->home_municipality_id,
                    type: $type,
                    status: $status,
                    createdAt: $createdAt,
                    municipalUserMap: $municipalUserMap,
                    provincialUserIds: $provincialUserIds,
                    isFlagged: false,
                );
            }

            if (count($claims) >= 500) {
                DB::table('claims')->insert($claims);
                $claims = [];
            }
        }

        if (!empty($claims)) {
            DB::table('claims')->insert($claims);
            $claims = [];
        }

        $this->command->info('  Normal claims created: ' . DB::table('claims')->count());

        // ---------------------------------------------------------------
        // PHASE 2: Cross-Municipality Claims (legitimate + suspicious)
        // Some people genuinely seek assistance where they work/travel.
        // ---------------------------------------------------------------
        $this->command->info('Phase 2: Generating cross-municipality claims...');

        $crossMunBeneficiaries = $beneficiaries->random(min(200, $beneficiaries->count()));

        foreach ($crossMunBeneficiaries as $beneficiary) {
            // Pick a different municipality
            $otherMunIds = array_filter($municipalityIds, fn($id) => $id !== $beneficiary->home_municipality_id);
            $targetMunId = $otherMunIds[array_rand($otherMunIds)];

            $type = self::ASSISTANCE_TYPES[array_rand(self::ASSISTANCE_TYPES)];
            $createdAt = Carbon::now()->subDays(rand(1, 180));

            // ~40% of cross-municipality claims get flagged
            $isFlagged = rand(1, 100) <= 40;

            $claims[] = $this->buildClaim(
                beneficiaryId: $beneficiary->id,
                municipalityId: $targetMunId,
                type: $type,
                status: $isFlagged ? 'UNDER_REVIEW' : $this->weightedStatus(),
                createdAt: $createdAt,
                municipalUserMap: $municipalUserMap,
                provincialUserIds: $provincialUserIds,
                isFlagged: $isFlagged,
                flagReason: $isFlagged ? "Cross-municipality claim: Beneficiary home is {$municipalities[$beneficiary->home_municipality_id]->name} but claimed from {$municipalities[$targetMunId]->name}" : null,
            );
        }

        if (!empty($claims)) {
            DB::table('claims')->insert($claims);
            $claims = [];
        }

        // ---------------------------------------------------------------
        // PHASE 3: Double-Dipping Fraud (same type within 30 days, different municipality)
        // These are the clear fraud cases the system should catch.
        // ---------------------------------------------------------------
        $this->command->info('Phase 3: Generating double-dipping fraud cases...');

        $doubleDippers = $beneficiaries->random(min(80, $beneficiaries->count()));

        foreach ($doubleDippers as $beneficiary) {
            $type = self::ASSISTANCE_TYPES[array_rand(self::ASSISTANCE_TYPES)];
            $baseDate = Carbon::now()->subDays(rand(5, 60));

            // First claim: from home municipality (legitimate)
            $claims[] = $this->buildClaim(
                beneficiaryId: $beneficiary->id,
                municipalityId: $beneficiary->home_municipality_id,
                type: $type,
                status: 'DISBURSED',
                createdAt: $baseDate,
                municipalUserMap: $municipalUserMap,
                provincialUserIds: $provincialUserIds,
                isFlagged: false,
            );

            // Second claim: same type, different municipality, within 10-25 days
            $otherMunIds = array_filter($municipalityIds, fn($id) => $id !== $beneficiary->home_municipality_id);
            $targetMunId = $otherMunIds[array_rand($otherMunIds)];
            $secondDate = $baseDate->copy()->addDays(rand(3, 25));

            // ~60% get detected and flagged, ~40% slip through (realistic detection rate)
            $detected = rand(1, 100) <= 60;

            $claims[] = $this->buildClaim(
                beneficiaryId: $beneficiary->id,
                municipalityId: $targetMunId,
                type: $type,
                status: $detected ? 'REJECTED' : 'DISBURSED',
                createdAt: $secondDate,
                municipalUserMap: $municipalUserMap,
                provincialUserIds: $provincialUserIds,
                isFlagged: $detected,
                flagReason: $detected
                    ? "DOUBLE-DIPPING: Received {$type} assistance {$baseDate->diffInDays($secondDate)} days ago from {$municipalities[$beneficiary->home_municipality_id]->name}"
                    : null,
                rejectionReason: $detected
                    ? "Duplicate assistance detected. Beneficiary already received {$type} aid from {$municipalities[$beneficiary->home_municipality_id]->name} on {$baseDate->format('M d, Y')}."
                    : null,
                riskAssessment: $detected ? [
                    'risk_level'     => 'HIGH',
                    'flags'          => ["Same {$type} assistance within 30 days from different municipality"],
                    'previous_claim' => [
                        'municipality' => $municipalities[$beneficiary->home_municipality_id]->name,
                        'date'         => $baseDate->format('Y-m-d'),
                        'days_ago'     => $baseDate->diffInDays($secondDate),
                    ],
                ] : null,
            );
        }

        if (!empty($claims)) {
            DB::table('claims')->insert($claims);
            $claims = [];
        }

        // ---------------------------------------------------------------
        // PHASE 4: High-Frequency Claimers (4-8 claims in 90 days)
        // ---------------------------------------------------------------
        $this->command->info('Phase 4: Generating high-frequency claimers...');

        $frequentClaimers = $beneficiaries->random(min(50, $beneficiaries->count()));

        foreach ($frequentClaimers as $beneficiary) {
            $numClaims = rand(4, 8);
            $baseDate = Carbon::now()->subDays(rand(10, 90));

            for ($i = 0; $i < $numClaims; $i++) {
                $type = self::ASSISTANCE_TYPES[array_rand(self::ASSISTANCE_TYPES)];
                $claimDate = $baseDate->copy()->addDays(rand(0, 85));

                // Pick random municipality (sometimes home, sometimes other)
                $munId = rand(1, 100) <= 70
                    ? $beneficiary->home_municipality_id
                    : $municipalityIds[array_rand($municipalityIds)];

                // Flag later claims in the sequence
                $isFlagged = $i >= 3;

                $claims[] = $this->buildClaim(
                    beneficiaryId: $beneficiary->id,
                    municipalityId: $munId,
                    type: $type,
                    status: $isFlagged ? 'UNDER_REVIEW' : $this->weightedStatus(),
                    createdAt: $claimDate,
                    municipalUserMap: $municipalUserMap,
                    provincialUserIds: $provincialUserIds,
                    isFlagged: $isFlagged,
                    flagReason: $isFlagged ? "HIGH FREQUENCY: Multiple claims detected within 90-day window" : null,
                    riskAssessment: $isFlagged ? [
                        'risk_level'  => 'HIGH',
                        'flags'       => ['High frequency claiming pattern', "Claim #{$i} within 90 days"],
                        'total_claims_90d' => $numClaims,
                    ] : null,
                );
            }
        }

        if (!empty($claims)) {
            DB::table('claims')->insert($claims);
            $claims = [];
        }

        // ---------------------------------------------------------------
        // PHASE 5: Near-Duplicate Name Fraud
        // Slight spelling variations registered as "different" people in
        // other municipalities. The phonetic search should catch these.
        // ---------------------------------------------------------------
        $this->command->info('Phase 5: Generating near-duplicate name fraud entries...');

        // Local data pools for Phase 5 beneficiary creation (avoids coupling to BeneficiarySeeder)
        $lastNames = [
            'Baguilat', 'Bahatan', 'Bimmayag', 'Bumayyao', 'Buyuccan', 'Dulnuan',
            'Dulawan', 'Gumangan', 'Haddad', 'Kinomis', 'Mumbaki', 'Palattao',
            'Pinkihan', 'Tindaan', 'Tumibay', 'Chadatan', 'Daluyo', 'Ngilin',
        ];
        $middleNames = [
            'Bugan', 'Dulnuan', 'Palattao', 'Gumangan', 'Bahatan', 'Santos',
            'Reyes', 'Cruz', 'Garcia', 'Torres', 'Ramos', 'Mendoza',
        ];
        $femaleRealNames = ['Josefina', 'Catalina', 'Mercedes', 'Teresita', 'Valentina', 'Remedios'];

        $namePairs = [
            ['Enrique',  'Enrike'],
            ['Ricardo',  'Rikardo'],
            ['Josefina', 'Josepina'],
            ['Francisco','Francisko'],
            ['Catalina', 'Katalina'],
            ['Gregorio', 'Grigorio'],
            ['Fernando', 'Fernandoh'],
            ['Mercedes', 'Mersedes'],
            ['Alfredo',  'Alpredo'],
            ['Teresita', 'Terecita'],
            ['Valentina','Balentina'],
            ['Nicolas',  'Nikolas'],
            ['Guillermo','Giyermo'],
            ['Remedios', 'Remedyos'],
            ['Salvador', 'Salbador'],
        ];

        foreach ($namePairs as [$realName, $fakeName]) {
            $lastName = $lastNames[array_rand($lastNames)];
            $birthdate = Carbon::now()->subYears(rand(50, 80))->subDays(rand(0, 364))->format('Y-m-d');
            $gender = in_array($realName, $femaleRealNames) || str_ends_with($realName, 'a') ? 'Female' : 'Male';

            // Municipality A: Real record
            $munA = $municipalityIds[array_rand($municipalityIds)];
            $middleName = $middleNames[array_rand($middleNames)];

            $realBeneficiaryId = DB::table('beneficiaries')->insertGetId([
                'uuid'                 => Str::uuid()->toString(),
                'home_municipality_id' => $munA,
                'first_name'           => $realName,
                'last_name'            => $lastName,
                'last_name_phonetic'   => soundex($lastName),
                'middle_name'          => $middleName,
                'suffix'               => null,
                'birthdate'            => $birthdate,
                'gender'               => $gender,
                'contact_number'       => '09' . rand(10, 99) . rand(1000000, 9999999),
                'address'              => "Poblacion, {$municipalities[$munA]->name}, Ifugao",
                'barangay'             => 'Poblacion',
                'id_type'              => 'Senior Citizen ID',
                'id_number'            => 'SC-IFU-' . str_pad((string) rand(1, 99999), 5, '0', STR_PAD_LEFT),
                'fingerprint_hash'     => null,
                'is_active'            => true,
                'created_by'           => null,
                'updated_by'           => null,
                'created_at'           => Carbon::now()->subMonths(rand(3, 18)),
                'updated_at'           => Carbon::now(),
                'deleted_at'           => null,
            ]);

            // Municipality B: Fake duplicate (misspelled name, same DOB)
            $otherMunIds = array_filter($municipalityIds, fn($id) => $id !== $munA);
            $munB = $otherMunIds[array_rand($otherMunIds)];

            $fakeBeneficiaryId = DB::table('beneficiaries')->insertGetId([
                'uuid'                 => Str::uuid()->toString(),
                'home_municipality_id' => $munB,
                'first_name'           => $fakeName,
                'last_name'            => $lastName,
                'last_name_phonetic'   => soundex($lastName),
                'middle_name'          => $middleNames[array_rand($middleNames)],
                'suffix'               => null,
                'birthdate'            => $birthdate,
                'gender'               => $gender,
                'contact_number'       => '09' . rand(10, 99) . rand(1000000, 9999999),
                'address'              => "Poblacion, {$municipalities[$munB]->name}, Ifugao",
                'barangay'             => 'Poblacion',
                'id_type'              => 'Barangay ID',
                'id_number'            => 'BID-IFU-' . str_pad((string) rand(1, 99999), 5, '0', STR_PAD_LEFT),
                'fingerprint_hash'     => null,
                'is_active'            => true,
                'created_by'           => null,
                'updated_by'           => null,
                'created_at'           => Carbon::now()->subMonths(rand(1, 6)),
                'updated_at'           => Carbon::now(),
                'deleted_at'           => null,
            ]);

            // Claims for the real person
            $type = self::ASSISTANCE_TYPES[array_rand(self::ASSISTANCE_TYPES)];
            $realClaimDate = Carbon::now()->subDays(rand(15, 45));

            $claims[] = $this->buildClaim(
                beneficiaryId: $realBeneficiaryId,
                municipalityId: $munA,
                type: $type,
                status: 'DISBURSED',
                createdAt: $realClaimDate,
                municipalUserMap: $municipalUserMap,
                provincialUserIds: $provincialUserIds,
                isFlagged: false,
            );

            // Claims for the fake duplicate (same type, close date)
            $fakeClaimDate = $realClaimDate->copy()->addDays(rand(5, 20));

            $claims[] = $this->buildClaim(
                beneficiaryId: $fakeBeneficiaryId,
                municipalityId: $munB,
                type: $type,
                status: rand(1, 100) <= 50 ? 'DISBURSED' : 'UNDER_REVIEW',
                createdAt: $fakeClaimDate,
                municipalUserMap: $municipalUserMap,
                provincialUserIds: $provincialUserIds,
                isFlagged: rand(1, 100) <= 50,
                flagReason: "POTENTIAL DUPLICATE: Phonetically similar to {$realName} {$lastName} in {$municipalities[$munA]->name}",
                riskAssessment: [
                    'risk_level'       => 'MEDIUM',
                    'flags'            => ['Phonetically similar name detected in another municipality'],
                    'similar_record'   => [
                        'name'           => "{$realName} {$lastName}",
                        'municipality'   => $municipalities[$munA]->name,
                        'levenshtein'    => levenshtein(strtolower($realName), strtolower($fakeName)),
                    ],
                ],
            );
        }

        if (!empty($claims)) {
            DB::table('claims')->insert($claims);
        }

        // ---------------------------------------------------------------
        // Summary
        // ---------------------------------------------------------------
        $totalClaims = DB::table('claims')->count();
        $flaggedClaims = DB::table('claims')->where('is_flagged', true)->count();
        $this->command->info("Total claims created: {$totalClaims}");
        $this->command->info("Flagged claims: {$flaggedClaims} (" . round(($flaggedClaims / max($totalClaims, 1)) * 100, 1) . '%)');
    }

    /**
     * Build a claim record array ready for bulk insert.
     */
    private function buildClaim(
        int $beneficiaryId,
        int $municipalityId,
        string $type,
        string $status,
        Carbon $createdAt,
        array $municipalUserMap,
        array $provincialUserIds,
        bool $isFlagged = false,
        ?string $flagReason = null,
        ?string $rejectionReason = null,
        ?array $riskAssessment = null,
    ): array {
        $range = self::AMOUNT_RANGES[$type];
        $amount = rand($range[0], $range[1]);
        // Round to nearest 100 (realistic — MSWDO doesn't give PHP 3,471)
        $amount = (int) round($amount / 100) * 100;

        $purposes = self::PURPOSES[$type];
        $purpose = $purposes[array_rand($purposes)];

        // Determine the processing user
        $processedBy = null;
        $approvedAt = null;
        $disbursedAt = null;
        $rejectedAt = null;

        if (in_array($status, ['APPROVED', 'DISBURSED', 'REJECTED', 'CANCELLED'])) {
            $munUsers = $municipalUserMap[$municipalityId] ?? $provincialUserIds;
            $processedBy = $munUsers[array_rand($munUsers)] ?? null;
        }

        if ($status === 'APPROVED') {
            $approvedAt = $createdAt->copy()->addDays(rand(1, 7))->format('Y-m-d H:i:s');
        } elseif ($status === 'DISBURSED') {
            $approvedAt = $createdAt->copy()->addDays(rand(1, 5))->format('Y-m-d H:i:s');
            $disbursedAt = Carbon::parse($approvedAt)->addDays(rand(1, 14))->format('Y-m-d H:i:s');
        } elseif (in_array($status, ['REJECTED', 'CANCELLED'])) {
            $rejectedAt = $createdAt->copy()->addDays(rand(1, 10))->format('Y-m-d H:i:s');
        }

        if ($rejectionReason === null && in_array($status, ['REJECTED', 'CANCELLED'])) {
            $rejectionReason = $this->randomRejectionReason();
        }

        return [
            'uuid'                 => Str::uuid()->toString(),
            'beneficiary_id'       => $beneficiaryId,
            'municipality_id'      => $municipalityId,
            'assistance_type'      => $type,
            'amount'               => $amount,
            'purpose'              => $purpose,
            'notes'                => rand(1, 100) <= 30 ? $this->randomNote() : null,
            'status'               => $status,
            'processed_by_user_id' => $processedBy,
            'approved_at'          => $approvedAt,
            'disbursed_at'         => $disbursedAt,
            'rejected_at'          => $rejectedAt,
            'rejection_reason'     => $rejectionReason,
            'is_flagged'           => $isFlagged,
            'flag_reason'          => $flagReason,
            'risk_assessment'      => $riskAssessment ? json_encode($riskAssessment) : null,
            'created_at'           => $createdAt->format('Y-m-d H:i:s'),
            'updated_at'           => Carbon::now()->format('Y-m-d H:i:s'),
            'deleted_at'           => null,
        ];
    }

    /**
     * Status distribution weighted toward realistic social welfare outcomes.
     * ~35% DISBURSED, ~20% APPROVED, ~25% PENDING/UNDER_REVIEW, ~15% REJECTED, ~5% CANCELLED.
     */
    private function weightedStatus(): string
    {
        $roll = rand(1, 100);

        if ($roll <= 35) return 'DISBURSED';
        if ($roll <= 55) return 'APPROVED';
        if ($roll <= 70) return 'PENDING';
        if ($roll <= 80) return 'UNDER_REVIEW';
        if ($roll <= 95) return 'REJECTED';
        return 'CANCELLED';
    }

    private function randomRejectionReason(): string
    {
        $reasons = [
            'Incomplete documentary requirements. Missing valid government-issued ID.',
            'Beneficiary has existing pending claim for the same assistance type.',
            'Exceeds maximum assistance amount for the fiscal quarter.',
            'Insufficient municipal funds for the requested assistance type.',
            'Duplicate claim detected — same beneficiary received identical aid within 30 days.',
            'Failed eligibility verification — household income exceeds DSWD threshold.',
            'Beneficiary is not a resident of the claiming municipality.',
            'Missing barangay clearance and indigency certificate.',
            'Claim withdrawn by beneficiary upon request.',
            'Fraudulent documents submitted — ID number does not match PhilSys records.',
        ];

        return $reasons[array_rand($reasons)];
    }

    private function randomNote(): string
    {
        $notes = [
            'Walk-in claimant, assisted by barangay captain.',
            'Referred by DSWD Field Office CAR.',
            'Follow-up claim from previous assistance cycle.',
            'Coordinated with provincial hospital social worker.',
            'Verified by barangay health worker.',
            'Accompanied by municipal social worker during processing.',
            'Rush processing requested — emergency case.',
            'Claimant is a solo parent with 4 dependents.',
            'Senior citizen — priority lane processing.',
            'PWD claimant, assisted by caregiver.',
            'Needs follow-up for documentary requirements.',
            'Released through check — no ATM available in barangay.',
            'Claimant traveled 4 hours from remote sitio.',
            'Endorsed by tribal council leader.',
            'Part of batch processing for Typhoon Egay victims.',
        ];

        return $notes[array_rand($notes)];
    }
}
