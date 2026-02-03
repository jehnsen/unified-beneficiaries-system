<?php

declare(strict_types=1);

use App\Http\Controllers\Api\DisbursementController;
use App\Http\Controllers\Api\IntakeController;
use Illuminate\Support\Facades\Route;

/**
 * Provincial UBIS API Routes
 *
 * All routes are protected by Sanctum authentication.
 * Tenant scoping is automatically applied via TenantScope.
 */

Route::middleware(['auth:sanctum'])->group(function () {

    // ============================================================
    // INTAKE MODULE - Search, Assess, and Create Claims
    // ============================================================
    Route::prefix('intake')->group(function () {

        // Step 1: Check for duplicate beneficiaries (Golden Record enforcement)
        Route::post('/check-duplicate', [IntakeController::class, 'checkDuplicate'])
            ->name('intake.check-duplicate');

        // Step 2: Assess fraud risk
        Route::post('/assess-risk', [IntakeController::class, 'assessRisk'])
            ->name('intake.assess-risk');

        // Step 3: Create a new claim (with automatic fraud detection)
        Route::post('/claims', [IntakeController::class, 'storeClaim'])
            ->name('intake.store-claim');

        // Get detailed fraud risk report for a beneficiary
        Route::get('/beneficiaries/{id}/risk-report', [IntakeController::class, 'getRiskReport'])
            ->name('intake.risk-report');

        // Get flagged claims for review
        Route::get('/flagged-claims', [IntakeController::class, 'getFlaggedClaims'])
            ->name('intake.flagged-claims');
    });

    // ============================================================
    // DISBURSEMENT MODULE - Approve, Reject, and Upload Proof
    // ============================================================
    Route::prefix('disbursement')->group(function () {

        // Approve a claim
        Route::post('/claims/{id}/approve', [DisbursementController::class, 'approve'])
            ->name('disbursement.approve')
            ->middleware('can:approve-claims'); // Add authorization policy

        // Reject a claim
        Route::post('/claims/{id}/reject', [DisbursementController::class, 'reject'])
            ->name('disbursement.reject')
            ->middleware('can:reject-claims'); // Add authorization policy

        // Upload disbursement proof (final step - marks claim as DISBURSED)
        Route::post('/claims/{id}/proof', [DisbursementController::class, 'uploadProof'])
            ->name('disbursement.upload-proof');

        // Get disbursement proofs for a claim
        Route::get('/claims/{id}/proofs', [DisbursementController::class, 'getProofs'])
            ->name('disbursement.get-proofs');
    });
});

/**
 * Public routes (if needed for testing or health checks)
 */
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'Provincial UBIS API',
        'version' => '1.0.0',
        'timestamp' => now()->toIso8601String(),
    ]);
})->name('health');
