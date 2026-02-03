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
- **`municipalities`**: The tenants. (`id`, `name`, `code`, `status`).
- **`beneficiaries`**:
    - Must include `last_name_phonetic` (Indexed) to store the `metaphone()` result for fast searching.
    - `home_municipality_id` tracks residence, not ownership.
- **`claims`**: The ledger. Must link to `municipality_id` (Who paid?).
- **`disbursement_proofs`**: The audit trail. (`photo_url`, `gps_lat`, `gps_lng`).

### The "Hybrid Search" Strategy (Fraud Detection)
When implementing `FraudDetectionService::checkDuplicates()`:
1.  **Layer 1 (DB Filter):** Query `beneficiaries` using the `last_name_phonetic` column (Fast Index Scan).
2.  **Layer 2 (PHP Logic):** Take the results and use PHP's `levenshtein()` to rank them by similarity to the First Name + Last Name.
3.  **Threshold:** Flag as "Risk" if Levenshtein distance < 3.

### Coding Standards
- **Strict Typing:** Always use `declare(strict_types=1);` and return types.
- **Security:**
    - Never expose `id` (Auto-increment) in public URLs if possible; use `uuid` or mask it.
    - Always use `DB::transaction()` for Claim processing.
- **Comments:** Explain the *WHY*, not the *HOW*. (e.g., "Use Soundex here to catch 'Enrike' vs 'Enrique' spelling errors").

## Common Commands & Scaffolding
- **New Feature:** `php artisan make:model FeatureName -mrc --api` (Migration, Resource, Controller).
- **Request** Create in `app/Requests/`.
- **Interface** Create in `app/Interfaces/`.
- **Service:** Create in `app/Services/`.
- **Repository:** Create in `app/Repositories/` and bind in `RepositoryServiceProvider`.

## Critical Business Rules
1.  **Inter-LGU Visibility:** If Municipality A searches for a person, and that person exists in Municipality B, show the RECORD but MASK the sensitive notes (e.g., "Medical Assistance - Details Hidden").
2.  **Double Dipping:** A claim is "Fraudulent" if the beneficiary received ANY assistance of the SAME type within the last 30 days from ANY municipality.

## Definition of Done
1.  Code compiles with no errors.
2.  Migration is idempotent.
3.  Logic handles the "Provincial vs Municipal" scope correctly.
4.  API returns proper HTTP codes (200, 201, 403, 422).