<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Claim;
use App\Services\FraudDetectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FraudAlertController extends Controller
{
    public function __construct(
        private readonly FraudDetectionService $fraudDetectionService
    ) {}

    /**
     * Display detailed fraud alert information.
     * Route model binding automatically injects the claim via UUID (bypassing TenantScope for fraud alerts).
     *
     * This endpoint provides comprehensive fraud alert details including:
     * - Beneficiary information (respecting inter-LGU data masking)
     * - Complete claim details with submitted documents
     * - Detection analysis with matched records from cross-municipality check
     * - Red flags and confidence scores
     * - Activity timeline
     *
     * @param Claim $claim The flagged claim (injected via custom route binding)
     */
    public function show(Request $request, Claim $claim): JsonResponse
    {
        // Laravel automatically injects the flagged claim via custom route binding
        // Custom binding ensures claim is flagged and bypasses TenantScope for provincial users
        $user = $request->user();
        $isProvincial = $user->isProvincialStaff();
        $userMunicipalityId = $user->municipality_id;

        // Enforce authorization for municipal users
        if (!$isProvincial && $claim->municipality_id !== $userMunicipalityId) {
            return response()->json([
                'message' => 'Fraud alert not found or you do not have permission to view it.',
            ], 403);
        }

        // Load relationships
        $claim->load([
            'beneficiary',
            'municipality',
            'disbursementProofs',
            'processedBy'
        ]);

        $beneficiary = $claim->beneficiary;
        $riskAssessment = $claim->risk_assessment ?? [];
        $alertCode = 'ALT-' . str_pad((string) $claim->id, 3, '0', STR_PAD_LEFT);

        // ============================================================
        // 1. BENEFICIARY INFORMATION
        // ============================================================
        // Apply cross-LGU masking if the beneficiary belongs to a different municipality
        $isSameMunicipality = $isProvincial || ($beneficiary->home_municipality_id === $userMunicipalityId);

        $beneficiaryInfo = [
            'id' => $beneficiary->id,
            'beneficiary_id' => $beneficiary->beneficiary_id,
            'name' => $beneficiary->full_name,
            'barangay' => $beneficiary->barangay,
            'contact' => $isSameMunicipality ? $beneficiary->contact_number : '***-***-****',
            'total_claims' => $beneficiary->claims()->count(),
            'total_amount_received' => (float) $beneficiary->claims()
                ->where('status', 'DISBURSED')
                ->sum('amount'),
            'home_municipality' => $beneficiary->homeMunicipality?->name ?? 'Unknown',
        ];

        // ============================================================
        // 2. CLAIM DETAILS
        // ============================================================
        $claimDetails = [
            'claim_id' => $claim->id,
            'type' => $claim->assistance_type,
            'amount' => (float) $claim->amount,
            'purpose' => $claim->purpose,
            'date_filed' => $claim->created_at->toIso8601String(),
            'status' => $claim->status,
            'submitted_documents' => $claim->disbursementProofs->map(fn ($proof) => [
                'type' => $this->inferDocumentType($proof->photo_url),
                'url' => $proof->photo_url,
                'uploaded_at' => $proof->created_at->toIso8601String(),
            ]),
            'municipality' => $claim->municipality->name,
            'notes' => $isSameMunicipality ? $claim->notes : 'Details hidden - Different municipality',
        ];

        // ============================================================
        // 3. DETECTION ANALYSIS
        // ============================================================
        // Re-run fraud detection to get fresh matched results
        $duplicateCheck = $this->fraudDetectionService->checkDuplicates(
            $beneficiary->first_name,
            $beneficiary->last_name,
            $beneficiary->birthdate->format('Y-m-d'),
            $beneficiary->id
        );

        $matchedResults = [];
        foreach ($duplicateCheck['matches'] ?? [] as $match) {
            $matchedBeneficiary = $match['beneficiary'];
            $matchedMunicipality = $matchedBeneficiary->homeMunicipality;

            // Get recent claims for this matched beneficiary
            $recentClaims = Claim::withoutGlobalScopes()
                ->where('beneficiary_id', $matchedBeneficiary->id)
                ->where('status', '!=', 'REJECTED')
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get();

            $matchedResults[] = [
                'municipality' => $matchedMunicipality?->name ?? 'Unknown',
                'municipality_code' => $matchedMunicipality?->code ?? 'N/A',
                'beneficiary_id' => $matchedBeneficiary->beneficiary_id,
                'name' => $matchedBeneficiary->full_name,
                'similarity_score' => $match['similarity_score'],
                'claim_type' => $recentClaims->first()?->assistance_type ?? 'Unknown',
                'claims_count' => $recentClaims->count(),
                'same_assistance_type' => $recentClaims->where('assistance_type', $claim->assistance_type)->isNotEmpty(),
            ];
        }

        // Calculate confidence score from risk assessment
        $confidenceScore = $riskAssessment['confidence_score'] ??
            ($duplicateCheck['risk_level'] === 'HIGH' ? 92 :
            ($duplicateCheck['risk_level'] === 'MEDIUM' ? 65 : 35));

        $detectionAnalysis = [
            'method' => 'Cross-Municipality Database Match',
            'confidence_score' => $confidenceScore,
            'matched_results' => $matchedResults,
        ];

        // ============================================================
        // 4. RED FLAGS
        // ============================================================
        $redFlags = [];

        // Parse flag_reason to extract individual red flags
        if ($claim->flag_reason) {
            $flagReasonParts = explode(';', $claim->flag_reason);
            foreach ($flagReasonParts as $flagPart) {
                $redFlags[] = [
                    'description' => trim($flagPart),
                    'detected_at' => $claim->created_at->toIso8601String(),
                ];
            }
        }

        // Add additional red flags from risk assessment
        if (isset($riskAssessment['flags'])) {
            foreach ($riskAssessment['flags'] as $flag) {
                $redFlags[] = [
                    'description' => $flag,
                    'detected_at' => $claim->created_at->toIso8601String(),
                ];
            }
        }

        // ============================================================
        // 5. ACTIVITY TIMELINE
        // ============================================================
        $timeline = [
            [
                'timestamp' => $claim->created_at->toIso8601String(),
                'event' => 'Alert Generated',
                'description' => 'Automatic detection via cross-database matching',
                'actor' => 'System',
            ],
        ];

        if ($claim->status === 'UNDER_REVIEW') {
            $timeline[] = [
                'timestamp' => $claim->updated_at->toIso8601String(),
                'event' => 'Alert Assigned',
                'description' => 'Auto-assigned to ' . ($claim->processedBy?->name ?? 'Maria Cruz') . ' based on workload',
                'actor' => 'System',
            ];
        }

        if ($claim->processed_by_user_id) {
            $timeline[] = [
                'timestamp' => $claim->updated_at->toIso8601String(),
                'event' => 'Under Investigation',
                'description' => 'Assigned to ' . $claim->processedBy->name,
                'actor' => $claim->processedBy->name,
            ];
        }

        // ============================================================
        // 6. ALERT SUMMARY
        // ============================================================
        $alertType = 'Unknown';
        if (str_contains($claim->flag_reason ?? '', 'DUPLICATE') ||
            str_contains($claim->flag_reason ?? '', 'SAME TYPE')) {
            $alertType = 'Duplicate Claim';
        } elseif (str_contains($claim->flag_reason ?? '', 'HIGH FREQUENCY') ||
                  str_contains($claim->flag_reason ?? '', 'Multiple claims')) {
            $alertType = 'Multiple Claims';
        } elseif (str_contains($claim->flag_reason ?? '', 'IDENTITY') ||
                  str_contains($claim->flag_reason ?? '', 'mismatch')) {
            $alertType = 'Identity Mismatch';
        }

        return response()->json([
            'data' => [
                'alert' => [
                    'id' => $claim->id,
                    'code' => $alertCode,
                    'type' => $alertType,
                    'severity' => $riskAssessment['risk_level'] ?? 'MEDIUM',
                    'status' => $claim->status,
                    'description' => $claim->flag_reason,
                    'created_at' => $claim->created_at->toIso8601String(),
                    'assigned_to' => $claim->processedBy?->name ?? null,
                ],
                'beneficiary' => $beneficiaryInfo,
                'claim' => $claimDetails,
                'detection_analysis' => $detectionAnalysis,
                'red_flags' => $redFlags,
                'activity_timeline' => $timeline,
            ],
        ]);
    }

    /**
     * Assign fraud alert to a specific user for investigation.
     * Route model binding automatically injects the claim via UUID.
     */
    public function assign(Request $request, Claim $claim): JsonResponse
    {
        // Laravel automatically injects the flagged claim via custom route binding
        $request->validate([
            'assigned_to_user_id' => 'required|exists:users,id',
        ]);

        $user = $request->user();
        $isProvincial = $user->isProvincialStaff();
        $userMunicipalityId = $user->municipality_id;

        // Enforce authorization for municipal users
        if (!$isProvincial && $claim->municipality_id !== $userMunicipalityId) {
            return response()->json([
                'message' => 'Fraud alert not found or you do not have permission to modify it.',
            ], 403);
        }

        $claim->update([
            'processed_by_user_id' => $request->assigned_to_user_id,
            'status' => 'UNDER_REVIEW',
        ]);

        return response()->json([
            'message' => 'Fraud alert assigned successfully.',
            'data' => [
                'claim_id' => $claim->id,
                'assigned_to' => $claim->processedBy->name,
                'status' => $claim->status,
            ],
        ]);
    }

    /**
     * Add investigation note to fraud alert.
     * Route model binding automatically injects the claim via UUID.
     */
    public function addNote(Request $request, Claim $claim): JsonResponse
    {
        // Laravel automatically injects the flagged claim via custom route binding
        $request->validate([
            'note' => 'required|string|max:1000',
        ]);

        $user = $request->user();
        $isProvincial = $user->isProvincialStaff();
        $userMunicipalityId = $user->municipality_id;

        // Enforce authorization for municipal users
        if (!$isProvincial && $claim->municipality_id !== $userMunicipalityId) {
            return response()->json([
                'message' => 'Fraud alert not found or you do not have permission to modify it.',
            ], 403);
        }

        // Append note to existing notes with timestamp and author
        $timestamp = now()->toIso8601String();
        $newNote = "[{$timestamp}] {$user->name}: {$request->note}";
        $existingNotes = $claim->notes ? $claim->notes . "\n\n" : '';

        $claim->update([
            'notes' => $existingNotes . $newNote,
        ]);

        return response()->json([
            'message' => 'Investigation note added successfully.',
            'data' => [
                'claim_id' => $claim->id,
                'note' => $newNote,
            ],
        ], 201);
    }

    /**
     * Infer document type from file URL.
     */
    private function inferDocumentType(string $url): string
    {
        $url = strtolower($url);

        if (str_contains($url, 'death') || str_contains($url, 'certificate')) {
            return 'Death Certificate';
        }
        if (str_contains($url, 'barangay') || str_contains($url, 'clearance')) {
            return 'Barangay Clearance';
        }
        if (str_contains($url, 'valid') || str_contains($url, 'id')) {
            return 'Valid ID';
        }

        return 'Supporting Document';
    }
}
