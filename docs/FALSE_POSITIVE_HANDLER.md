# False Positive Handler - Feature Documentation

## Overview

The **False Positive Handler** is a critical business logic feature that prevents the UBIS fraud detection system from repeatedly flagging verified distinct beneficiaries. When administrators manually verify that two phonetically similar beneficiaries (e.g., "Juan Cruz" and "Juan Kruz") are actually different people, the system permanently remembers this verification and excludes the pair from future fraud detection checks.

## Problem Statement

### Before Implementation

The fraud detection system uses phonetic matching (SOUNDEX) and Levenshtein distance to identify potential duplicate beneficiaries. While this catches legitimate fraud cases, it also generates false positives:

**Example Scenario:**
1. Municipal staff creates a claim for "Juan Cruz"
2. System flags "Juan Kruz" as a potential duplicate (similar name)
3. Admin investigates and confirms they are different people
4. **Next week**: System flags them AGAIN when "Juan Cruz" makes another claim
5. Admin wastes time re-investigating the SAME pair
6. This repeats every time either beneficiary makes a claim ❌

### After Implementation

**Improved Workflow:**
1. Municipal staff creates a claim for "Juan Cruz"
2. System flags "Juan Kruz" as a potential duplicate
3. Admin investigates and confirms they are different people
4. **NEW**: Admin whitelists the pair via API: `POST /intake/whitelist-pair`
5. System stores verification in `verified_distinct_pairs` table
6. **Next week**: System automatically SKIPS this pair - no flag! ✅
7. Admins can focus on real fraud cases instead of re-checking false positives

## Architecture

### Database Schema

**Table:** `verified_distinct_pairs`

```sql
CREATE TABLE verified_distinct_pairs (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    uuid CHAR(36) UNIQUE NOT NULL,

    -- The pair (normalized: smaller ID first)
    beneficiary_a_id BIGINT UNSIGNED NOT NULL,
    beneficiary_b_id BIGINT UNSIGNED NOT NULL,

    -- Verification status
    verification_status ENUM(
        'VERIFIED_DISTINCT',   -- Different people (whitelist)
        'VERIFIED_DUPLICATE',  -- Same person (blacklist)
        'UNDER_REVIEW',        -- Investigation ongoing
        'REVOKED'             -- Verification removed
    ) DEFAULT 'VERIFIED_DISTINCT',

    -- Audit trail for similarity metrics
    similarity_score INT NULL COMMENT 'Score when verified (0-100)',
    levenshtein_distance INT NULL COMMENT 'Distance when verified',

    -- Verification metadata
    verification_reason TEXT NOT NULL,
    notes TEXT NULL,

    -- Who verified and when
    verified_by_user_id BIGINT UNSIGNED NOT NULL,
    verified_at TIMESTAMP NOT NULL,

    -- Revocation metadata
    revoked_by_user_id BIGINT UNSIGNED NULL,
    revoked_at TIMESTAMP NULL,
    revocation_reason TEXT NULL,

    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    deleted_at TIMESTAMP NULL,

    -- Performance indexes
    INDEX vdp_pair_ab (beneficiary_a_id, beneficiary_b_id, verification_status),
    INDEX vdp_pair_ba (beneficiary_b_id, beneficiary_a_id, verification_status),
    INDEX vdp_ben_a_status (beneficiary_a_id, verification_status),
    INDEX vdp_ben_b_status (beneficiary_b_id, verification_status),

    -- Prevent duplicate pairs
    UNIQUE KEY vdp_unique_pair (beneficiary_a_id, beneficiary_b_id),

    FOREIGN KEY (beneficiary_a_id) REFERENCES beneficiaries(id),
    FOREIGN KEY (beneficiary_b_id) REFERENCES beneficiaries(id),
    FOREIGN KEY (verified_by_user_id) REFERENCES users(id),
    FOREIGN KEY (revoked_by_user_id) REFERENCES users(id)
);
```

### Key Design Decisions

#### 1. Pair Normalization
**Rule:** Always store `beneficiary_a_id < beneficiary_b_id`

**Why?** Prevents duplicate entries like (5, 10) and (10, 5) which represent the same pair.

**Implementation:**
- Model's `boot()` method automatically swaps IDs if `a > b` during creation
- Repository's `findPair()` normalizes IDs before querying
- This enables bidirectional lookup: `findPair(5, 10)` === `findPair(10, 5)`

```php
// Model boot method
static::creating(function ($pair) {
    if ($pair->beneficiary_a_id > $pair->beneficiary_b_id) {
        $temp = $pair->beneficiary_a_id;
        $pair->beneficiary_a_id = $pair->beneficiary_b_id;
        $pair->beneficiary_b_id = $temp;
    }
});
```

#### 2. Bidirectional Indexes
Two separate compound indexes enable fast lookups regardless of search order:

```sql
INDEX vdp_pair_ab (beneficiary_a_id, beneficiary_b_id, verification_status)
INDEX vdp_pair_ba (beneficiary_b_id, beneficiary_a_id, verification_status)
```

**Performance:** O(log n) lookup with ~1-5ms query time for 500 pairs

#### 3. Status Enum
Supports multiple verification states beyond simple whitelist/blacklist:

- **VERIFIED_DISTINCT**: Confirmed as different people → Exclude from fraud detection
- **VERIFIED_DUPLICATE**: Confirmed as same person → Still flag (data cleanup needed)
- **UNDER_REVIEW**: Investigation ongoing → Still flag
- **REVOKED**: Verification removed → Resume flagging

## Integration with Fraud Detection

### Modified Service Layer

**File:** `app/Services/FraudDetectionService.php`

#### New Method: `isWhitelisted()`

```php
private function isWhitelisted(int $beneficiaryAId, int $beneficiaryBId): bool
{
    $pair = $this->verifiedPairRepository->findPair($beneficiaryAId, $beneficiaryBId);

    // Only skip fraud detection if pair is explicitly marked as VERIFIED_DISTINCT
    return $pair && $pair->verification_status === 'VERIFIED_DISTINCT';
}
```

#### Modified: `checkRisk()`

```php
// After getting $potentialMatches from phonetic search
$targetBeneficiary = Beneficiary::where('first_name', $firstName)
    ->where('last_name', $lastName)
    ->where('birthdate', $birthdate)
    ->first();

if ($targetBeneficiary) {
    $potentialMatches = $potentialMatches->filter(function ($match) use ($targetBeneficiary) {
        return !$this->isWhitelisted($targetBeneficiary->id, $match->id);
    });
}
```

#### Modified: `checkDuplicates()`

```php
foreach ($potentialMatches as $beneficiary) {
    // Skip if this pair is whitelisted as distinct
    if ($excludeBeneficiaryId && $this->isWhitelisted($excludeBeneficiaryId, $beneficiary->id)) {
        continue;
    }

    // ... existing duplicate checking logic ...
}
```

### Impact Analysis

**Performance:**
- Whitelist check: O(log n) with indexes ≈ 1-5ms per check
- Negligible impact on fraud detection (< 1% overhead)

**Backward Compatibility:**
- Fully compatible. Empty whitelist = current behavior
- No changes to existing API contracts

**Fraud Detection Accuracy:**
- Reduces false positive rate while maintaining true positive detection
- Admins can still flag VERIFIED_DUPLICATE pairs for review

## API Endpoints

### 1. Whitelist Pair

**Endpoint:** `POST /api/intake/whitelist-pair`

**Request:**
```json
{
  "beneficiary_a_uuid": "9d4e8f2a-1234-5678-90ab-cdef12345678",
  "beneficiary_b_uuid": "9d4e8f2a-5678-1234-90ab-cdef87654321",
  "verification_status": "VERIFIED_DISTINCT",
  "verification_reason": "Verified via PhilSys ID - different individuals",
  "notes": "Juan Cruz (PSN-1234567890) vs Juan Kruz (PSN-0987654321)",
  "similarity_score": 85,
  "levenshtein_distance": 2
}
```

**Response (201):**
```json
{
  "data": {
    "pair_id": "9d4e8f2a-9999-8888-90ab-cdef12345678",
    "beneficiary_a": {
      "uuid": "9d4e8f2a-1234-5678-90ab-cdef12345678",
      "name": "Juan Dela Cruz"
    },
    "beneficiary_b": {
      "uuid": "9d4e8f2a-5678-1234-90ab-cdef87654321",
      "name": "Juan Kruz"
    },
    "verification_status": "VERIFIED_DISTINCT",
    "verified_at": "2026-02-06T10:30:00Z",
    "verified_by": "Admin User"
  },
  "message": "Beneficiary pair successfully verified."
}
```

### 2. Get Verified Pairs

**Endpoint:** `GET /api/intake/verified-pairs?per_page=15&status=VERIFIED_DISTINCT`

**Response (200):**
```json
{
  "data": [
    {
      "pair_id": "9d4e8f2a-9999-8888-90ab-cdef12345678",
      "beneficiary_a": {
        "uuid": "9d4e8f2a-1234-5678-90ab-cdef12345678",
        "name": "Juan Dela Cruz",
        "municipality": "Lagawe"
      },
      "beneficiary_b": {
        "uuid": "9d4e8f2a-5678-1234-90ab-cdef87654321",
        "name": "Juan Kruz",
        "municipality": "Lamut"
      },
      "verification_status": "VERIFIED_DISTINCT",
      "similarity_score": 85,
      "verified_at": "2026-02-06T10:30:00Z",
      "verified_by": "Admin User"
    }
  ],
  "meta": {
    "current_page": 1,
    "total": 45,
    "per_page": 15
  }
}
```

### 3. Revoke Verified Pair

**Endpoint:** `DELETE /api/intake/whitelist-pair/{pair}`

**Request:**
```json
{
  "revocation_reason": "New evidence shows same person - PhilSys records merged"
}
```

**Response (200):**
```json
{
  "message": "Pair verification revoked successfully."
}
```

## Authorization Matrix

| Action | Provincial Staff | Municipal Staff |
|--------|-----------------|-----------------|
| **Whitelist pair** | Any pair (all municipalities) | Only if at least ONE beneficiary is from their municipality |
| **Revoke pair** | Any pair | Only pairs they have access to |
| **View pairs** | All pairs | Only pairs involving their municipality |

### Authorization Logic

```php
// In IntakeController::whitelistPair()
if ($user->isMunicipalStaff()) {
    $userMunicipalityId = $user->municipality_id;

    // Municipal staff can only whitelist if at least one beneficiary is from their municipality
    $hasAccess = $beneficiaryA->home_municipality_id === $userMunicipalityId
              || $beneficiaryB->home_municipality_id === $userMunicipalityId;

    if (!$hasAccess) {
        return response()->json(['error' => 'Authorization denied...'], 403);
    }
}
```

## Use Cases

### Use Case 1: Similar Names (Different People)

**Scenario:**
- Beneficiary A: "Maria Santos" (Lagawe)
- Beneficiary B: "Maria Santoz" (Lamut)
- System flags as duplicates (Levenshtein distance = 2)

**Resolution:**
1. Admin investigates PhilSys IDs
2. Confirms they are different people
3. Whitelists as `VERIFIED_DISTINCT`
4. Future claims skip this pair

### Use Case 2: Phonetic Variations

**Scenario:**
- Beneficiary A: "Enrique Gonzales"
- Beneficiary B: "Enrike Gonzalez"
- System flags as duplicates (SOUNDEX match + Levenshtein = 3)

**Resolution:**
1. Admin checks voter registration records
2. Confirms different birthdates and addresses
3. Whitelists as `VERIFIED_DISTINCT`

### Use Case 3: Legitimate Duplicate (Data Cleanup)

**Scenario:**
- Beneficiary A: "Pedro Reyes" (created by Lagawe staff)
- Beneficiary B: "Pedro Reyes" (created by Lamut staff)
- Same birthdate, same PhilSys ID

**Resolution:**
1. Admin confirms they are the SAME person (duplicate record)
2. Whitelists as `VERIFIED_DUPLICATE`
3. Initiates data cleanup to merge records
4. System continues flagging until cleanup complete

### Use Case 4: Revocation (Wrong Verification)

**Scenario:**
- Pair previously marked as `VERIFIED_DISTINCT`
- New PhilSys integration reveals they are the same person

**Resolution:**
1. Admin revokes the verification
2. Provides reason: "PhilSys records merged - confirmed duplicate"
3. Pair status changes to `REVOKED`
4. System resumes flagging the pair

## Testing

### Manual Testing Flow

**Setup:**
1. Seed database with test data
2. Login as Provincial or Municipal user
3. Identify two beneficiaries with similar names

**Test Steps:**

**Step 1: Initial Flagging**
```bash
POST /api/intake/check-duplicate
{
  "first_name": "Juan",
  "last_name": "Cruz",
  "birthdate": "1990-01-01"
}

# Expected: System flags "Juan Kruz" as duplicate
```

**Step 2: Whitelist the Pair**
```bash
POST /api/intake/whitelist-pair
{
  "beneficiary_a_uuid": "<uuid-juan-cruz>",
  "beneficiary_b_uuid": "<uuid-juan-kruz>",
  "verification_status": "VERIFIED_DISTINCT",
  "verification_reason": "Verified via PhilSys ID check - different individuals",
  "similarity_score": 85,
  "levenshtein_distance": 2
}

# Expected: 201 Created
```

**Step 3: Verify No More Flagging**
```bash
POST /api/intake/check-duplicate
{
  "first_name": "Juan",
  "last_name": "Cruz",
  "birthdate": "1990-01-01"
}

# Expected: System does NOT flag "Juan Kruz"
```

**Step 4: View Verified Pairs**
```bash
GET /api/intake/verified-pairs?status=VERIFIED_DISTINCT

# Expected: Shows the whitelisted pair
```

**Step 5: Revoke Verification**
```bash
DELETE /api/intake/whitelist-pair/<pair-uuid>
{
  "revocation_reason": "Testing revocation - wrong verification"
}

# Expected: 200 Success
```

**Step 6: Verify Flagging Resumes**
```bash
POST /api/intake/check-duplicate
{
  "first_name": "Juan",
  "last_name": "Cruz",
  "birthdate": "1990-01-01"
}

# Expected: System flags "Juan Kruz" again
```

### Automated Testing

**Unit Tests:**
- Repository: Bidirectional lookup, normalization, CRUD
- Service: Whitelist filtering, risk calculation with whitelist

**Feature Tests:**
- Whitelist creation returns 201
- Authorization (Provincial vs Municipal)
- Duplicate prevention (409 Conflict)
- Revocation workflow
- Fraud detection integration

## Performance Considerations

**Expected Load:**
- 20 municipalities × ~500 beneficiaries = ~10,000 beneficiaries
- Realistic: ~100-500 verified pairs (based on phonetic false positives)

**Index Performance:**
- `beneficiary_a_id + beneficiary_b_id + verification_status` → O(log n) lookup
- With 500 pairs: ~9 index reads (log₂ 500)
- Negligible impact on fraud detection (<1ms per check)

**Optimization (Future):**
- Implement caching for high-frequency checks (>1000/day)
- Cache TTL: 1 hour
- Invalidate on create/revoke

## Rollback Plan

If issues occur:

```bash
# Rollback migration
php artisan migrate:rollback --step=1

# Revert code changes
git revert <commit-hash>
```

**Data Preservation:**
- Soft deletes ensure no data loss
- Existing fraud detection works unchanged if feature is disabled
- Whitelist table is separate from core tables

## Future Enhancements

### Suggested Improvements

1. **Bulk Whitelisting**: Upload CSV of verified distinct pairs
2. **AI Verification**: Suggest pairs for whitelisting based on claim patterns
3. **Expiration**: Auto-revoke whitelists after X months (require re-verification)
4. **Confidence Levels**: Track how many times a pair was flagged before whitelisting
5. **Dashboard Widget**: Show top 10 most-whitelisted pairs (potential system tuning)
6. **Notification**: Alert admin when revoked pair is flagged again
7. **Audit Log**: Detailed history of all whitelist changes

## Support & Troubleshooting

**Common Issues:**

**Issue:** "Authorization denied" when whitelisting
- **Solution**: Municipal staff can only whitelist if at least one beneficiary is from their municipality

**Issue:** "This pair has already been verified" (409 Conflict)
- **Solution**: Pair already exists. View it via `GET /intake/verified-pairs` or revoke and recreate

**Issue:** Fraud detection still flags whitelisted pair
- **Solution**: Check verification status is `VERIFIED_DISTINCT` (not `VERIFIED_DUPLICATE` or `REVOKED`)

## Contact

For questions or issues:
- **Technical**: UBIS Development Team
- **Documentation**: See `docs/API_DOCUMENTATION.md`
- **Postman Collection**: See `docs/UBIS_Provincial_Grid_API.postman_collection.json`
