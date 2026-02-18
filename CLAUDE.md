# Project Context: UBIS (Unified Beneficiary Identity System) - Provincial Grid

**Role:** Senior Laravel Architect & Government Systems Specialist.
**Goal:** Build a secure, multi-tenant API connecting a Provincial Capitol to 20+ Municipalities for social welfare fraud detection.

## Tech Stack
- **Framework:** Laravel 11.x (PHP 8.2+)
- **Database:** MySQL 8.0 (Use InnoDB)
- **Auth:** Laravel Sanctum (API Token Authentication)
- **API Standard:** JSON:API resource format (clean, consistent responses)

## Architectural Guidelines

### 1. The "Provincial Grid" Philosophy (Crucial)
- **Data Sovereignty:** "Beneficiaries" are SHARED global records (Provincial Asset).
- **Tenant Scope:**
    - **Provincial Users (Super Admin):** Can view ALL records.
    - **Municipal Users (Tenants):** Can only VIEW/EDIT records tagged with their `municipality_id`, BUT can "Search" the global index for fraud checks.
- **The "Golden Record":** Never create duplicate beneficiaries. Always try to match an existing "Global ID" first.

### 2. Design Patterns (Strict Enforcement)
- **Repository Pattern:** DO NOT put query logic in Controllers. Use `BeneficiaryRepository`.
- **Service Layer:** DO NOT put business logic in Controllers. Use `FraudDetectionService`.
- **Form Requests:** DO NOT validate in Controllers. Use `StoreClaimRequest`.
- **Resources:** Always transform data using `JsonResource` classes to mask sensitive fields for cross-LGU views.

## Core Implementation Details

### Database Schema Rules
- **`municipalities`**: The tenants. (`id`, `uuid`, `name`, `code`, `status`).
- **`beneficiaries`**:
    - Must include `last_name_phonetic` (Indexed) to store the `metaphone()` result for fast searching.
    - `home_municipality_id` tracks residence, not ownership.
    - Includes `uuid` for secure public API routing.
- **`claims`**: The ledger. Must link to `municipality_id` (Who paid?). Includes `uuid` for route binding.
- **`disbursement_proofs`**: The audit trail. (`photo_url`, `gps_lat`, `gps_lng`).
- **`system_settings`**: Runtime configuration store. (`key`, `value`, `data_type`, `category`, `validation_rules`).
    - Enables dynamic fraud detection thresholds without code redeployment.
    - Provincial Admin only access.
- **`verified_distinct_pairs`**: False positive handler. Tracks manually verified beneficiary pairs.
    - Normalized pair storage: `beneficiary_a_id < beneficiary_b_id` (prevents duplicates).
    - Status enum: `VERIFIED_DISTINCT`, `VERIFIED_DUPLICATE`, `UNDER_REVIEW`, `REVOKED`.
    - Bidirectional indexes for fast lookups regardless of pair order.

### The "Hybrid Search" Strategy (Fraud Detection)
When implementing `FraudDetectionService::checkDuplicates()`:
1.  **Layer 1 (DB Filter):** Query `beneficiaries` using the `last_name_phonetic` column (Fast Index Scan).
2.  **Layer 2 (Whitelist Filter):** Exclude verified distinct pairs from `verified_distinct_pairs` table to prevent repeat false positives.
3.  **Layer 3 (PHP Logic):** Take the results and use PHP's `levenshtein()` to rank them by similarity to the First Name + Last Name.
4.  **Threshold:** Flag as "Risk" if Levenshtein distance <= configurable threshold (default: 3, via `system_settings.LEVENSHTEIN_DISTANCE_THRESHOLD`).
5.  **Similarity Score:** Calculate `max(0, 100 - (distance * 10))` for ranking.
6.  **Risk Levels:**
    - **LOW:** No matches found.
    - **MEDIUM:** Similarity >= 70% OR 2+ matches.
    - **HIGH:** Similarity >= 90% OR 3+ matches.

### Coding Standards
- **Strict Typing:** Always use `declare(strict_types=1);` and return types.
- **Security:**
    - ALL public-facing routes MUST use `uuid` for route binding (e.g., `/api/beneficiaries/{beneficiary:uuid}`).
    - NEVER expose auto-increment `id` in URLs or JSON responses.
    - Always use `DB::transaction()` for Claim processing and System Settings updates.
    - Use row locking (`lockForUpdate()`) for concurrent-sensitive operations.
- **Comments:** Explain the *WHY*, not the *HOW*. (e.g., "Use Soundex here to catch 'Enrike' vs 'Enrique' spelling errors").
- **Caching:** Use cache-first strategy with tag-based invalidation for frequently accessed config (see `ConfigurationService`).

## Key Services & Repositories

### Services
- **`FraudDetectionService`**: Core fraud detection logic with phonetic matching, Levenshtein distance calculation, whitelist filtering, and risk assessment.
    - Methods: `checkRisk()`, `checkDuplicates()`, `checkDuplicatesByUuid()`, `generateRiskReportByUuid()`, `isWhitelisted()`.
    - Returns: `RiskAssessmentResult` DTO for risk checks.
- **`ConfigurationService`**: Cache-first runtime configuration store with type-safe getters.
    - Methods: `get()`, `set()`, `getInt()`, `getFloat()`, `getBool()`, `getString()`, `invalidateCache()`, `flushCache()`.
- **`DashboardService`**: Tenant-scoped dashboard analytics and reporting.
    - Encapsulates Provincial vs Municipal data access pattern.
    - Methods: `forUser()`, `claimQuery()`, `beneficiaryQuery()`, `getSummary()`, `getMetricsCards()`, `getAssistanceDistribution()`, `getDisbursementVelocity()`, `getRecentTransactions()`, `getFraudAlerts()`, `getSavingsTicker()`, `getDoubleDipperLeaderboard()`, `getTopAssistanceTypes()`.

### DTOs (Data Transfer Objects)
- **`RiskAssessmentResult`** (`app/DTOs/RiskAssessmentResult.php`): Value object for fraud detection results.
    - Properties: `isRisky`, `riskLevel`, `details`, `matchingBeneficiaries`, `recentClaims`.
    - Method: `toArray()` for JSON serialization.

### Repositories (All follow Interface-Implementation pattern)
- **`BeneficiaryRepositoryInterface`** → `EloquentBeneficiaryRepository`
- **`ClaimRepositoryInterface`** → `EloquentClaimRepository`
- **`MunicipalityRepositoryInterface`** → `EloquentMunicipalityRepository`
- **`SystemSettingRepositoryInterface`** → `EloquentSystemSettingRepository`
- **`VerifiedDistinctPairRepositoryInterface`** → `EloquentVerifiedDistinctPairRepository`

**Binding:** All interfaces are bound in `app/Providers/RepositoryServiceProvider.php`.

---

## Common Commands & Scaffolding
- **New Feature:** `php artisan make:model FeatureName -mrc --api` (Migration, Resource, Controller).
- **Request:** Create in `app/Http/Requests/`.
- **Interface:** Create in `app/Interfaces/`.
- **Service:** Create in `app/Services/`.
- **DTO:** Create in `app/DTOs/` for value objects and data transfer objects.
- **Repository:** Create in `app/Repositories/` and bind in `RepositoryServiceProvider`.
- **Seeder:** Run all seeders with `php artisan db:seed` or specific with `php artisan db:seed --class=SystemSettingSeeder`.
- **Cache Clear:** `php artisan cache:clear` or `php artisan cache:forget system_settings:*` for config cache.

## Recent Features & Enhancements

### 1. Dynamic Rules Engine (Runtime Configuration)
**What it does:** Allows Provincial Admins to adjust fraud detection sensitivity WITHOUT code redeployment.

**Key Components:**
- **Service:** `ConfigurationService` - Cache-first config store with 1-hour TTL.
- **Repository:** `EloquentSystemSettingRepository` - CRUD with transaction safety and row locking.
- **Controller:** `SettingsController` - REST API for system settings management.
- **API Endpoints:**
    - `GET /api/admin/settings` - List all settings (grouped by category).
    - `GET /api/admin/settings/{setting:uuid}` - Show single setting.
    - `PUT /api/admin/settings/{setting:uuid}` - Update setting value/description.

**Configurable Thresholds:**
- `RISK_THRESHOLD_DAYS` (default: 90, range: 1-365) - Risk assessment lookback period.
- `SAME_TYPE_THRESHOLD_DAYS` (default: 30, range: 1-180) - Double-dipping detection window.
- `HIGH_FREQUENCY_THRESHOLD` (default: 3, range: 1-10) - Max claims before flagging as high frequency.
- `LEVENSHTEIN_DISTANCE_THRESHOLD` (default: 3, range: 0-10) - Name similarity matching sensitivity.

**Authorization:** Provincial Staff + Admin role only (via `can:manage-settings` gate).

**Documentation:** [docs/DYNAMIC_RULES_ENGINE.md](docs/DYNAMIC_RULES_ENGINE.md)

---

### 2. False Positive Handler (Verified Distinct Pairs)
**What it does:** Prevents repeated flagging of manually verified distinct beneficiaries (e.g., twins, same-name siblings).

**Key Components:**
- **Model:** `VerifiedDistinctPair` - Auto-normalizes pair order, tracks verification metadata.
- **Repository:** `EloquentVerifiedDistinctPairRepository` - Bidirectional pair lookup with normalization.
- **Service Integration:** `FraudDetectionService::isWhitelisted()` - Filters out verified pairs in risk checks.
- **API Endpoints:**
    - `POST /api/intake/whitelist-pair` - Create verified pair.
    - `DELETE /api/intake/whitelist-pair/{pair:uuid}` - Revoke verification.
    - `GET /api/intake/verified-pairs` - List verified pairs (paginated).

**Workflow:**
1. Intake officer receives fraud alert for beneficiary pair.
2. After investigation, officer confirms they are distinct persons (e.g., same name but different addresses).
3. Officer calls `POST /api/intake/whitelist-pair` with beneficiary UUIDs, status `VERIFIED_DISTINCT`, and justification.
4. Future fraud checks automatically skip this pair.
5. If later evidence suggests they ARE duplicates, Provincial Admin can revoke the verification.

**Authorization Matrix:**
- **Provincial Admin:** Can verify/revoke ANY pair globally.
- **Municipal Staff:** Can only verify pairs where BOTH beneficiaries belong to their municipality OR at least one belongs to their municipality (requires justification).

**Documentation:** [docs/FALSE_POSITIVE_HANDLER.md](docs/FALSE_POSITIVE_HANDLER.md)

---

### 3. UUID-Based Route Security
**What it does:** Prevents exposure of auto-increment IDs in public APIs for improved security and data privacy.

**Implementation:**
- All public-facing models (`Beneficiary`, `Claim`, `Municipality`, `User`, `VerifiedDistinctPair`, `SystemSetting`) include a `uuid` column (unique, indexed).
- Models use `getRouteKeyName()` to return `'uuid'` for route binding.
- All API routes use `{model:uuid}` format instead of `{model:id}`.

**Migration:** `2026_02_05_121729_add_uuid_to_public_facing_tables.php` backfills UUIDs using MySQL 8.0 `UUID()` function.

**Example:**
```php
// OLD (Insecure):
GET /api/beneficiaries/12345

// NEW (Secure):
GET /api/beneficiaries/550e8400-e29b-41d4-a716-446655440000
```

---

## Critical Business Rules
1.  **Inter-LGU Visibility:** If Municipality A searches for a person, and that person exists in Municipality B, show the RECORD but MASK the sensitive notes (e.g., "Medical Assistance - Details Hidden").
2.  **Double Dipping (Configurable):** A claim is "Fraudulent" if the beneficiary received ANY assistance of the SAME type within the configurable threshold (default: 30 days via `SAME_TYPE_THRESHOLD_DAYS`) from ANY municipality.
3.  **High Frequency Detection:** Flag beneficiary as risky if claim count exceeds threshold (default: 3 claims via `HIGH_FREQUENCY_THRESHOLD`) within risk period (default: 90 days via `RISK_THRESHOLD_DAYS`).
4.  **Whitelist Verification:** Once a beneficiary pair is marked as `VERIFIED_DISTINCT` in `verified_distinct_pairs`, they will NOT be flagged again in fraud detection unless the verification is revoked.
5.  **Authorization Matrix:**
    - **Provincial Admin:** Can update system settings, verify/revoke pairs globally.
    - **Municipal Staff:** Can only verify pairs where BOTH beneficiaries belong to their municipality OR at least one belongs to their municipality (with justification).
6.  **UUID Route Security:** All models with public-facing APIs MUST implement `getRouteKeyName()` returning `'uuid'`. Never use auto-increment IDs in URLs.

## Definition of Done
1.  Code compiles with no errors.
2.  Migration is idempotent.
3.  Logic handles the "Provincial vs Municipal" scope correctly.
4.  API returns proper HTTP codes (200, 201, 403, 422).
5.  All public-facing models use UUID route binding (never auto-increment IDs in URLs).
6.  All new models include `uuid` column (unique, indexed) and implement `getRouteKeyName()`.
7.  Form Request validation is used (never validate in controllers).
8.  Repository pattern is followed (never query in controllers).
9.  Service layer handles business logic (controllers only orchestrate).
10. Resources transform responses with proper data masking for cross-LGU views.
11. Code uses `declare(strict_types=1)` and explicit return types.
12. Sensitive operations use `DB::transaction()` with row locking where needed.
13. Cache invalidation strategy is implemented for frequently accessed data.
14. Authorization gates are properly configured (Provincial vs Municipal permissions).