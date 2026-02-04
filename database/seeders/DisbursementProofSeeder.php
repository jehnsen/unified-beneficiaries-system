<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * Creates disbursement proof records for all DISBURSED claims.
 *
 * GPS coordinates are realistic locations within each Ifugao municipality,
 * reflecting actual municipal hall and barangay hall disbursement points.
 */
class DisbursementProofSeeder extends Seeder
{
    /**
     * GPS center coordinates per municipality (latitude, longitude).
     * Used as anchor points with slight random offsets to simulate
     * disbursement at various barangay locations.
     */
    private const MUNICIPALITY_COORDS = [
        'Lagawe'        => [16.8280, 121.1064],
        'Lamut'         => [16.6667, 121.1500],
        'Kiangan'       => [16.7833, 121.0833],
        'Banaue'        => [16.9133, 121.0578],
        'Hungduan'      => [16.9333, 121.0167],
        'Hingyon'       => [16.8167, 121.0333],
        'Mayoyao'       => [16.9667, 121.1667],
        'Alfonso Lista' => [16.5833, 121.1167],
        'Aguinaldo'     => [16.8833, 121.1833],
        'Asipulo'       => [16.7500, 121.0500],
        'Tinoc'         => [16.7000, 120.9333],
    ];

    private const DEVICES = [
        'Samsung Galaxy A15 (Android 14)',
        'Vivo Y17s (Android 13)',
        'OPPO A78 (Android 13)',
        'Realme C55 (Android 13)',
        'Samsung Galaxy A05s (Android 13)',
        'Xiaomi Redmi 13C (Android 13)',
        'iPhone SE (iOS 17)',
        'Huawei Nova Y61 (Android 12)',
        'Desktop — Chrome 120 / Windows 10',
        'Desktop — Edge 120 / Windows 11',
        'Lenovo Tab M10 (Android 12)',
        'Samsung Galaxy Tab A9 (Android 13)',
    ];

    public function run(): void
    {
        $municipalities = DB::table('municipalities')->get()->keyBy('id');

        // Get all disbursed claims with their municipality info
        $disbursedClaims = DB::table('claims')
            ->where('status', 'DISBURSED')
            ->whereNotNull('disbursed_at')
            ->get();

        $this->command->info("Creating disbursement proofs for {$disbursedClaims->count()} disbursed claims...");

        // Map municipality_id -> user ids for captured_by
        $municipalUsers = DB::table('users')
            ->whereNotNull('municipality_id')
            ->get()
            ->groupBy('municipality_id');

        $proofs = [];

        foreach ($disbursedClaims as $claim) {
            $municipality = $municipalities[$claim->municipality_id] ?? null;
            $munName = $municipality?->name ?? 'Lagawe';

            // Get GPS coordinates with slight random offset (simulates different barangay locations)
            $coords = self::MUNICIPALITY_COORDS[$munName] ?? [16.8280, 121.1064];
            $lat = $coords[0] + (rand(-500, 500) / 100000); // ~5m offset range
            $lng = $coords[1] + (rand(-500, 500) / 100000);

            // Pick a user from this municipality to be the capturer
            $munUsers = $municipalUsers[$claim->municipality_id] ?? collect();
            $capturedBy = $munUsers->isNotEmpty()
                ? $munUsers->random()->id
                : DB::table('users')->whereNull('municipality_id')->first()->id;

            $disbursedAt = Carbon::parse($claim->disbursed_at);
            $capturedAt = $disbursedAt->copy()->addMinutes(rand(0, 120));

            $proofs[] = [
                'claim_id'             => $claim->id,
                'photo_url'            => "disbursements/{$claim->municipality_id}/{$claim->id}/beneficiary_photo.jpg",
                'signature_url'        => "disbursements/{$claim->municipality_id}/{$claim->id}/signature.png",
                'id_photo_url'         => rand(1, 100) <= 80
                    ? "disbursements/{$claim->municipality_id}/{$claim->id}/valid_id.jpg"
                    : null,
                'additional_documents' => rand(1, 100) <= 30
                    ? json_encode([
                        "disbursements/{$claim->municipality_id}/{$claim->id}/barangay_clearance.pdf",
                        "disbursements/{$claim->municipality_id}/{$claim->id}/indigency_cert.pdf",
                    ])
                    : null,
                'latitude'             => round($lat, 8),
                'longitude'            => round($lng, 8),
                'location_accuracy'    => rand(3, 25) . 'm',
                'captured_at'          => $capturedAt->format('Y-m-d H:i:s'),
                'captured_by_user_id'  => $capturedBy,
                'device_info'          => self::DEVICES[array_rand(self::DEVICES)],
                'ip_address'           => $this->randomLocalIp(),
                'created_at'           => $capturedAt->format('Y-m-d H:i:s'),
                'updated_at'           => $capturedAt->format('Y-m-d H:i:s'),
            ];

            if (count($proofs) >= 500) {
                DB::table('disbursement_proofs')->insert($proofs);
                $proofs = [];
            }
        }

        if (!empty($proofs)) {
            DB::table('disbursement_proofs')->insert($proofs);
        }

        $total = DB::table('disbursement_proofs')->count();
        $this->command->info("Total disbursement proofs created: {$total}");
    }

    /**
     * Generate a plausible LAN/WiFi IP address (municipal office networks).
     */
    private function randomLocalIp(): string
    {
        // Mix of local network and mobile carrier IPs
        if (rand(1, 100) <= 60) {
            // Local office network
            return '192.168.' . rand(1, 10) . '.' . rand(2, 254);
        }

        // Mobile network (Globe/Smart PH ranges)
        return rand(112, 124) . '.' . rand(198, 210) . '.' . rand(1, 254) . '.' . rand(1, 254);
    }
}
