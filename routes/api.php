<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AssistanceTypeController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BeneficiaryController;
use App\Http\Controllers\Api\ClaimController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DisbursementController;
use App\Http\Controllers\Api\FraudAlertController;
use App\Http\Controllers\Api\IntakeController;
use App\Http\Controllers\Api\MunicipalityController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

/**
 * Provincial UBIS API Routes
 *
 * Tenant scoping is automatically applied via TenantScope on Claims.
 * Beneficiaries are provincial assets visible to all authenticated users
 * (with cross-LGU data masking via BeneficiaryResource).
 *
 * UUID-based routing: All routes use UUIDs instead of integer IDs for security.
 */

// ================================================================
// PUBLIC ROUTES (No authentication required)
// ================================================================
Route::post('/auth/login', [AuthController::class, 'login'])->name('auth.login');

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'Provincial UBIS API',
        'version' => '1.0.0',
        'timestamp' => now()->toIso8601String(),
    ]);
})->name('health');

// ================================================================
// PROTECTED ROUTES (Sanctum authentication required)
// ================================================================
Route::middleware(['auth:sanctum'])->group(function () {

    // ============================================================
    // AUTH - Logout & Profile
    // ============================================================
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');

    // ============================================================
    // BENEFICIARIES CRUD
    // ============================================================
    Route::prefix('beneficiaries')->group(function () {
        Route::get('/', [BeneficiaryController::class, 'index'])->name('beneficiaries.index');
        Route::get('/{beneficiary:uuid}', [BeneficiaryController::class, 'show'])->name('beneficiaries.show');
        Route::post('/', [BeneficiaryController::class, 'store'])->name('beneficiaries.store');
        Route::put('/{beneficiary:uuid}', [BeneficiaryController::class, 'update'])->name('beneficiaries.update');
        Route::delete('/{beneficiary:uuid}', [BeneficiaryController::class, 'destroy'])->name('beneficiaries.destroy');
    });

    // ============================================================
    // MUNICIPALITIES CRUD
    // ============================================================
    Route::prefix('municipalities')->group(function () {
        Route::get('/', [MunicipalityController::class, 'index'])->name('municipalities.index');
        Route::get('/{municipality:uuid}', [MunicipalityController::class, 'show'])->name('municipalities.show');
        Route::post('/', [MunicipalityController::class, 'store'])->name('municipalities.store');
        Route::put('/{municipality:uuid}', [MunicipalityController::class, 'update'])->name('municipalities.update');
        Route::delete('/{municipality:uuid}', [MunicipalityController::class, 'destroy'])->name('municipalities.destroy');
    });

    // ============================================================
    // USERS CRUD
    // ============================================================
    Route::prefix('users')->group(function () {
        Route::get('/', [UserController::class, 'index'])->name('users.index');
        Route::get('/{user:uuid}', [UserController::class, 'show'])->name('users.show');
        Route::post('/', [UserController::class, 'store'])->name('users.store');
        Route::put('/{user:uuid}', [UserController::class, 'update'])->name('users.update');
        Route::delete('/{user:uuid}', [UserController::class, 'destroy'])->name('users.destroy');
    });

    // ============================================================
    // CLAIMS (Read-only listing; write via Intake/Disbursement)
    // ============================================================
    Route::get('/claims', [ClaimController::class, 'index'])->name('claims.index');
    Route::get('/claims/{claim:uuid}', [ClaimController::class, 'show'])->name('claims.show');

    // ============================================================
    // REFERENCE DATA
    // ============================================================
    Route::get('/assistance-types', [AssistanceTypeController::class, 'index'])
        ->name('assistance-types.index');

    // ============================================================
    // DASHBOARD
    // ============================================================
    Route::prefix('dashboard')->group(function () {
        Route::get('/summary', [DashboardController::class, 'summary'])
            ->name('dashboard.summary');
        Route::get('/metrics-cards', [DashboardController::class, 'metricsCards'])
            ->name('dashboard.metrics-cards');
        Route::get('/assistance-distribution', [DashboardController::class, 'assistanceDistribution'])
            ->name('dashboard.assistance-distribution');
        Route::get('/disbursement-velocity', [DashboardController::class, 'disbursementVelocity'])
            ->name('dashboard.disbursement-velocity');
        Route::get('/recent-transactions', [DashboardController::class, 'recentTransactions'])
            ->name('dashboard.recent-transactions');
        Route::get('/fraud-alerts', [DashboardController::class, 'fraudAlerts'])
            ->name('dashboard.fraud-alerts');
        Route::get('/savings-ticker', [DashboardController::class, 'savingsTicker'])
            ->name('dashboard.savings-ticker');
        Route::get('/double-dipper-leaderboard', [DashboardController::class, 'doubleDipperLeaderboard'])
            ->name('dashboard.double-dipper-leaderboard');
        Route::get('/top-assistance-types', [DashboardController::class, 'topAssistanceTypes'])
            ->name('dashboard.top-assistance-types');
    });

    // ============================================================
    // FRAUD ALERTS - Detailed fraud detection and investigation
    // ============================================================
    Route::prefix('fraud-alerts')->group(function () {
        Route::get('/{claim:uuid}', [FraudAlertController::class, 'show'])
            ->name('fraud-alerts.show');
        Route::post('/{claim:uuid}/assign', [FraudAlertController::class, 'assign'])
            ->name('fraud-alerts.assign');
        Route::post('/{claim:uuid}/notes', [FraudAlertController::class, 'addNote'])
            ->name('fraud-alerts.add-note');
    });

    // ============================================================
    // REPORTS - Generate downloadable reports
    // ============================================================
    Route::prefix('reports')->group(function () {
        Route::get('/monthly-disbursement', [ReportController::class, 'monthlyDisbursement'])
            ->name('reports.monthly-disbursement');
        Route::get('/beneficiary-demographics', [ReportController::class, 'beneficiaryDemographics'])
            ->name('reports.beneficiary-demographics');
        Route::get('/fraud-detection', [ReportController::class, 'fraudDetection'])
            ->name('reports.fraud-detection');
        Route::get('/budget-utilization', [ReportController::class, 'budgetUtilization'])
            ->name('reports.budget-utilization');
    });

    // ============================================================
    // INTAKE MODULE - Search, Assess, and Create Claims
    // ============================================================
    Route::prefix('intake')->group(function () {
        Route::post('/check-duplicate', [IntakeController::class, 'checkDuplicate'])
            ->name('intake.check-duplicate');
        Route::post('/assess-risk', [IntakeController::class, 'assessRisk'])
            ->name('intake.assess-risk');
        Route::post('/claims', [IntakeController::class, 'storeClaim'])
            ->name('intake.store-claim');
        Route::get('/beneficiaries/{beneficiary:uuid}/risk-report', [IntakeController::class, 'getRiskReport'])
            ->name('intake.risk-report');
        Route::get('/flagged-claims', [IntakeController::class, 'getFlaggedClaims'])
            ->name('intake.flagged-claims');

        // Whitelist management routes (False Positive Handler)
        Route::post('/whitelist-pair', [IntakeController::class, 'whitelistPair'])
            ->name('intake.whitelist-pair');
        Route::delete('/whitelist-pair/{pair:uuid}', [IntakeController::class, 'revokePair'])
            ->name('intake.revoke-pair');
        Route::get('/verified-pairs', [IntakeController::class, 'getVerifiedPairs'])
            ->name('intake.verified-pairs');
    });

    // ============================================================
    // DISBURSEMENT MODULE - Approve, Reject, and Upload Proof
    // ============================================================
    Route::prefix('disbursement')->group(function () {
        Route::post('/claims/{claim:uuid}/approve', [DisbursementController::class, 'approve'])
            ->name('disbursement.approve')
            ->middleware('can:approve-claims');
        Route::post('/claims/{claim:uuid}/reject', [DisbursementController::class, 'reject'])
            ->name('disbursement.reject')
            ->middleware('can:reject-claims');
        Route::post('/claims/{claim:uuid}/proof', [DisbursementController::class, 'uploadProof'])
            ->name('disbursement.upload-proof');
        Route::get('/claims/{claim:uuid}/proofs', [DisbursementController::class, 'getProofs'])
            ->name('disbursement.get-proofs');
    });
});
