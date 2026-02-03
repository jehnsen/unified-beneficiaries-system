# Provincial UBIS - Backend Architecture Overview

## System Design Philosophy

The Provincial UBIS follows **Clean Architecture** principles with strict separation of concerns:

```
┌─────────────────────────────────────────────────────┐
│                   API Layer                         │
│  (Controllers, Requests, Resources)                 │
└─────────────────┬───────────────────────────────────┘
                  │
┌─────────────────▼───────────────────────────────────┐
│                Service Layer                        │
│  (Business Logic, Fraud Detection)                  │
└─────────────────┬───────────────────────────────────┘
                  │
┌─────────────────▼───────────────────────────────────┐
│              Repository Layer                       │
│  (Data Access Abstraction)                          │
└─────────────────┬───────────────────────────────────┘
                  │
┌─────────────────▼───────────────────────────────────┐
│                 Model Layer                         │
│  (Eloquent Models, Relationships)                   │
└─────────────────┬───────────────────────────────────┘
                  │
┌─────────────────▼───────────────────────────────────┐
│                  Database                           │
│  (MySQL 8.0 with InnoDB)                            │
└─────────────────────────────────────────────────────┘
```

---

## Core Architectural Patterns

### 1. Repository Pattern
**Purpose:** Decouple business logic from data access.

**Structure:**
```
Interface (Contract) → Implementation (Eloquent) → Binding (ServiceProvider)
```

**Benefits:**
- Easy to swap data sources (Eloquent → Redis, API, etc.)
- Testable (mock repositories in unit tests)
- Centralized query logic

**Files:**
- `app/Interfaces/BeneficiaryRepositoryInterface.php`
- `app/Interfaces/ClaimRepositoryInterface.php`
- `app/Repositories/EloquentBeneficiaryRepository.php`
- `app/Repositories/EloquentClaimRepository.php`
- `app/Providers/RepositoryServiceProvider.php`

---

### 2. Service Layer Pattern
**Purpose:** Encapsulate complex business logic.

**Example:** `FraudDetectionService`
- **Input:** Beneficiary data
- **Process:** Phonetic matching, claim history analysis
- **Output:** Risk assessment

**Benefits:**
- Single Responsibility (one service = one domain)
- Reusable across controllers
- Testable in isolation

**Files:**
- `app/Services/FraudDetectionService.php`

---

### 3. Multi-Tenancy via Global Scope
**Purpose:** Enforce data isolation between municipalities.

**Implementation:**
```php
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();

        // Provincial Staff → See ALL
        if ($user->municipality_id === null) {
            return;
        }

        // Municipal Staff → See ONLY their municipality
        $builder->where('municipality_id', $user->municipality_id);
    }
}
```

**Bypass Scope (for fraud checks):**
```php
Claim::withoutGlobalScope(TenantScope::class)->get();
```

**Files:**
- `app/Models/Scopes/TenantScope.php`

---

### 4. Request Validation Pattern
**Purpose:** Validate ALL inputs before reaching controllers.

**Benefits:**
- Security (prevent injection, XSS, etc.)
- Clean controllers (no validation clutter)
- Consistent error responses

**Files:**
- `app/Http/Requests/CheckDuplicateRequest.php`
- `app/Http/Requests/StoreClaimRequest.php`
- `app/Http/Requests/ApprovClaimRequest.php`
- `app/Http/Requests/RejectClaimRequest.php`
- `app/Http/Requests/UploadDisbursementProofRequest.php`

---

### 5. API Resource Pattern
**Purpose:** Transform database records into API responses with data masking.

**Example:**
```php
// If user is from different municipality:
'contact_number' => $isSameMunicipality ? $this->contact_number : '***-****-****'
```

**Files:**
- `app/Http/Resources/BeneficiaryResource.php`
- `app/Http/Resources/ClaimResource.php`
- `app/Http/Resources/DisbursementProofResource.php`

---

## Database Schema Design

### Entity Relationship Diagram

```
┌─────────────────┐
│ municipalities  │
│ ────────────── │
│ id (PK)         │
│ name            │
│ code            │
└────────┬────────┘
         │
         │ 1:N
         │
┌────────▼────────┐      ┌─────────────────┐
│ users           │      │ beneficiaries   │
│ ────────────── │      │ ────────────── │
│ id (PK)         │      │ id (PK)         │
│ municipality_id │◄─────┤ home_municipality_id
│ role (ENUM)     │      │ first_name      │
└────────┬────────┘      │ last_name       │
         │               │ last_name_phonetic (SOUNDEX)
         │               └────────┬────────┘
         │                        │
         │                        │ 1:N
         │                        │
         │               ┌────────▼────────┐
         │               │ claims          │
         │               │ ────────────── │
         │               │ id (PK)         │
         └───────────────┤ processed_by_user_id
                         │ beneficiary_id  │
                         │ municipality_id │
                         │ status (ENUM)   │
                         │ is_flagged      │
                         └────────┬────────┘
                                  │
                                  │ 1:N
                                  │
                         ┌────────▼──────────────┐
                         │ disbursement_proofs   │
                         │ ───────────────────── │
                         │ id (PK)               │
                         │ claim_id              │
                         │ photo_url             │
                         │ signature_url         │
                         │ latitude, longitude   │
                         └───────────────────────┘
```

### Critical Indexes

**For Fraud Detection (FAST):**
```sql
-- Beneficiaries
INDEX idx_phonetic (last_name_phonetic)
INDEX idx_name_dob (last_name, birthdate)
INDEX idx_fullname (first_name, last_name)

-- Claims
INDEX idx_beneficiary_timeline (beneficiary_id, created_at, status)
INDEX idx_municipal_reporting (municipality_id, status, created_at)
```

---

## The "Provincial Grid" Fraud Detection Engine

### How It Works

**Layer 1: Database Filter (Fast)**
```sql
SELECT * FROM beneficiaries
WHERE last_name_phonetic = SOUNDEX('Cruz')
```
- Uses indexed column for speed
- Returns ~10-20 candidates

**Layer 2: PHP Levenshtein (Accurate)**
```php
$distance = levenshtein('Juan Dela Cruz', 'Juan De La Cruz');
if ($distance < 3) {
    // Potential match!
}
```
- Ranks candidates by similarity
- Catches typos like "Enrique" vs "Enrike"

**Layer 3: Claim History Check (Cross-LGU)**
```php
$claims = Claim::withoutGlobalScope(TenantScope::class)
    ->where('beneficiary_id', $beneficiaryId)
    ->where('created_at', '>=', now()->subDays(90))
    ->get();
```
- Bypasses tenant scope
- Checks ALL municipalities

**Risk Factors:**
1. **Inter-LGU Claims:** Same person claimed from 2+ municipalities
2. **Double-Dipping:** Same assistance type within 30 days
3. **High Frequency:** 3+ claims in 90 days

**Output:** `RiskAssessmentResult` object
```php
[
    'is_risky' => true,
    'risk_level' => 'HIGH',
    'details' => 'Claimed from 3 municipalities | Received Medical assistance 15 days ago',
]
```

---

## API Workflow Examples

### Example 1: Create a New Claim

```
1. Client → POST /intake/check-duplicate
   ↓
2. Server → Search beneficiaries (SOUNDEX + Levenshtein)
   ↓
3. Server → Return matches (if any)
   ↓
4. Client → POST /intake/assess-risk
   ↓
5. Server → Run FraudDetectionService
   ↓
6. Server → Return risk assessment
   ↓
7. Client → POST /intake/claims
   ↓
8. Server → DB::transaction()
           ├── Find or create beneficiary (Golden Record)
           ├── Run fraud detection
           ├── Create claim with flags
           └── Commit
   ↓
9. Server → Return claim (with risk flags)
```

### Example 2: Disburse a Claim

```
1. Reviewer → POST /disbursement/claims/{id}/approve
   ↓
2. Server → Update status to APPROVED
   ↓
3. Field Staff → POST /disbursement/claims/{id}/proof
                 (upload photo, signature, GPS)
   ↓
4. Server → DB::transaction()
           ├── Store files to S3/local storage
           ├── Create disbursement_proof record
           ├── Update claim status to DISBURSED
           └── Commit
   ↓
5. Server → Return proof details
```

---

## Security Architecture

### 1. Authentication (Laravel Sanctum)
- Token-based API authentication
- Tokens scoped to user + device
- Revocable via `DELETE /sanctum/tokens/{id}`

### 2. Authorization Layers
- **Role-Based:** `ADMIN`, `ENCODER`, `REVIEWER`
- **Tenant-Based:** `TenantScope` auto-filters queries
- **Policy-Based:** Gates for sensitive actions

### 3. Data Masking
- Cross-municipality queries mask:
  - Contact numbers: `***-****-****`
  - Addresses: `[Hidden]`
  - ID numbers: `****`

### 4. Audit Logging
- All claim actions logged to `storage/logs/laravel.log`
- Includes: User ID, IP, timestamp, action

### 5. Input Validation
- **Form Requests:** Validate ALL inputs
- **SQL Injection:** Prevented via Eloquent parameterized queries
- **XSS:** API returns JSON (not HTML)

---

## File Structure Summary

```
app/
├── Http/
│   ├── Controllers/
│   │   ├── Api/
│   │   │   ├── IntakeController.php ........... Search, Assess, Create Claims
│   │   │   └── DisbursementController.php ...... Approve, Reject, Upload Proof
│   │   └── Controller.php ...................... Base controller
│   ├── Requests/
│   │   ├── CheckDuplicateRequest.php ........... Validation for duplicate checks
│   │   ├── StoreClaimRequest.php ............... Validation for claim creation
│   │   ├── ApprovClaimRequest.php .............. Validation for approval
│   │   ├── RejectClaimRequest.php .............. Validation for rejection
│   │   └── UploadDisbursementProofRequest.php .. Validation for proof upload
│   └── Resources/
│       ├── BeneficiaryResource.php ............. Beneficiary JSON transformation
│       ├── ClaimResource.php ................... Claim JSON transformation
│       └── DisbursementProofResource.php ....... Proof JSON transformation
│
├── Interfaces/
│   ├── BeneficiaryRepositoryInterface.php ...... Repository contract
│   └── ClaimRepositoryInterface.php ............ Repository contract
│
├── Repositories/
│   ├── EloquentBeneficiaryRepository.php ....... Beneficiary data access
│   └── EloquentClaimRepository.php ............. Claim data access
│
├── Services/
│   └── FraudDetectionService.php ............... Core fraud detection engine
│
├── Models/
│   ├── Municipality.php ........................ Municipality entity
│   ├── User.php ................................ User entity
│   ├── Beneficiary.php ......................... Beneficiary entity (with SOUNDEX)
│   ├── Claim.php ............................... Claim entity (with TenantScope)
│   ├── DisbursementProof.php ................... Proof entity
│   └── Scopes/
│       └── TenantScope.php ..................... Multi-tenancy global scope
│
└── Providers/
    └── RepositoryServiceProvider.php ........... Dependency injection bindings

database/
└── migrations/
    ├── 2024_01_01_000001_create_municipalities_table.php
    ├── 2024_01_01_000002_create_users_table.php
    ├── 2024_01_01_000003_create_beneficiaries_table.php
    ├── 2024_01_01_000004_create_claims_table.php
    └── 2024_01_01_000005_create_disbursement_proofs_table.php

routes/
└── api.php ..................................... API routes with Sanctum middleware
```

---

## Testing Strategy

### Unit Tests
```php
// Test Fraud Detection Service
public function test_detects_inter_lgu_fraud()
{
    $result = $this->fraudService->checkRisk('Juan', 'Cruz', '1990-01-01');
    $this->assertTrue($result->isRisky);
}
```

### Feature Tests
```php
// Test Claim Creation
public function test_creates_claim_with_fraud_flag()
{
    $response = $this->postJson('/api/intake/claims', $data);
    $response->assertStatus(201);
    $response->assertJson(['data' => ['is_flagged' => true]]);
}
```

### Integration Tests
- Test full workflow: Create → Approve → Disburse
- Test cross-municipality fraud detection

---

## Performance Optimizations

### 1. Database Indexes
- All foreign keys indexed
- Composite indexes for common queries
- SOUNDEX column indexed for speed

### 2. Eager Loading
```php
$claim = Claim::with(['beneficiary', 'municipality', 'processedBy'])->find($id);
```
- Reduces N+1 query problems

### 3. Caching (Future)
```php
Cache::remember("municipality:{$id}", 3600, fn() => Municipality::find($id));
```

### 4. Query Optimization
- Use `select()` to fetch only needed columns
- Use `chunk()` for large datasets

---

## Deployment Architecture

### Production Environment

```
┌──────────────┐
│  Cloudflare  │ ← CDN + DDoS Protection
└──────┬───────┘
       │
┌──────▼───────┐
│  Nginx/Apache │ ← Web Server
└──────┬───────┘
       │
┌──────▼───────┐
│  PHP-FPM 8.2 │ ← Application Server
└──────┬───────┘
       │
┌──────▼───────┐
│  Laravel 11  │ ← Backend API
└──────┬───────┘
       │
┌──────▼───────┐
│  MySQL 8.0   │ ← Database (with read replicas)
└──────────────┘
```

### Scaling Strategy
- **Horizontal:** Add more PHP-FPM workers
- **Vertical:** Increase server resources
- **Database:** Master-slave replication for read queries
- **Storage:** S3 for file uploads

---

## Key Business Rules (Recap)

1. **Golden Record Principle:** Never create duplicate beneficiaries
2. **Inter-LGU Visibility:** Provincial staff see all; Municipal staff see own
3. **Fraud Detection:** Automatic checks on every claim creation
4. **Double-Dipping Rule:** Same assistance type within 30 days = flagged
5. **Audit Trail:** Every disbursement requires photo + signature + GPS
6. **Transaction Integrity:** All critical operations wrapped in DB transactions

---

## Maintainability Principles

1. **Strict Typing:** All functions have type hints and return types
2. **SOLID Principles:** Single Responsibility, Dependency Inversion
3. **Comments:** Explain the "WHY", not the "WHAT"
4. **No Magic Numbers:** Use constants (`RISK_THRESHOLD_DAYS = 90`)
5. **Separation of Concerns:** API → Service → Repository → Model

---

## Future Enhancements

### Phase 2 Features
- [ ] **Biometric Integration:** Fingerprint deduplication
- [ ] **Real-time Notifications:** SMS/Email alerts for claim status
- [ ] **Analytics Dashboard:** Municipal spending reports
- [ ] **Mobile App:** Field staff app for disbursement
- [ ] **Document OCR:** Auto-extract data from ID photos

### Phase 3 Features
- [ ] **AI Fraud Detection:** Machine learning for pattern recognition
- [ ] **Blockchain Audit Trail:** Immutable transaction log
- [ ] **Geographic Heatmaps:** Claim distribution visualization
- [ ] **API Rate Limiting:** Per-municipality quotas

---

## Support & Documentation

- **Architecture:** This file
- **API Documentation:** [API_DOCUMENTATION.md](./API_DOCUMENTATION.md)
- **Setup Guide:** [SETUP.md](./SETUP.md)
- **Project Instructions:** [CLAUDE.md](./CLAUDE.md)

---

## Contributors

- **Senior Laravel Architect:** [Your Name]
- **Government Systems Specialist:** [Consultant Name]

**Last Updated:** 2024-01-01
**Version:** 1.0.0
